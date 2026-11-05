<?php

namespace App\Modules\Social;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Boîte de réception des réseaux : commentaires et messages privés Facebook (Messenger) et Instagram,
 * reçus par le webhook de Meta ou relevés régulièrement, avec réponse publique, réponse privée, masquage,
 * attribution, réponses automatiques par mot-clé et création du prospect dans le CRM.
 */
class Inbox
{
    public const NETWORKS = ['facebook', 'instagram'];

    public function __construct(protected SocialOAuth $oauth) {}

    protected function graph(): string
    {
        return SocialOAuth::GRAPH;
    }

    protected function fail(string $m): never
    {
        throw new \DomainException($m);
    }

    protected function check($res, string $who = 'Meta'): void
    {
        if (! $res->ok()) {
            $this->fail($who.' : '.Str::limit((string) ($res->json('error.message') ?? 'erreur '.$res->status()), 220));
        }
    }

    /** Page Facebook à laquelle le compte est rattaché (Instagram : page reliée). */
    public static function pageId(object $acc): string
    {
        return $acc->network === 'instagram' ? (string) (json_decode($acc->meta ?? '{}', true)['page_id'] ?? '') : (string) $acc->external_id;
    }

    // ------------------------------------------------------------------ réception

    protected function conversation(object $acc, string $kind, string $thread, array $extra): object
    {
        $q = DB::table('so_conversations')->where('account_id', $acc->id)->where('kind', $kind)->where('thread_ref', $thread);
        if (! $q->exists()) {
            DB::table('so_conversations')->insertOrIgnore(['company_id' => $acc->company_id, 'account_id' => $acc->id, 'network' => $acc->network, 'kind' => $kind, 'thread_ref' => $thread,
                'status' => 'open', 'unread' => 0, 'created_at' => now(), 'updated_at' => now()] + array_filter($extra, fn ($v) => $v !== null));
        } elseif ($extra = array_filter($extra, fn ($v) => $v !== null && $v !== '')) {
            $q->update($extra + ['updated_at' => now()]);
        }

        return $q->first();
    }

    /** Ajoute un message (sans doublon) et met la conversation à jour. Renvoie true si le message est nouveau. */
    public function add(object $acc, string $kind, string $thread, array $msg, array $conv = []): bool
    {
        $c = $this->conversation($acc, $kind, $thread, $conv);
        $in = $msg['direction'] === 'in';
        $ok = DB::table('so_messages')->insertOrIgnore([
            'company_id' => $acc->company_id, 'conversation_id' => $c->id, 'external_id' => $msg['external_id'] ?? null, 'direction' => $msg['direction'],
            'author_name' => isset($msg['author']) ? Str::limit((string) $msg['author'], 150, '') : null, 'text' => isset($msg['text']) ? Str::limit((string) $msg['text'], 8000, '') : null,
            'private' => (bool) ($msg['private'] ?? false), 'sent_by' => $msg['sent_by'] ?? null, 'sent_at' => $msg['at'] ?? now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        if (! $ok) {
            return false;
        }
        $upd = ['last_text' => Str::limit((string) ($msg['text'] ?? '(pièce jointe)'), 500, ''), 'last_at' => $msg['at'] ?? now(), 'updated_at' => now()];
        if ($in) {
            $upd['unread'] = DB::raw('unread + 1');
            $upd['status'] = 'open';
        }
        DB::table('so_conversations')->where('id', $c->id)->update($upd);
        if ($in && empty($msg['quiet'])) {
            $this->onIncoming($acc, $c, $kind, $msg);
        }

        return true;
    }

    protected function onIncoming(object $acc, object $conv, string $kind, array $msg): void
    {
        $company = Company::find($acc->company_id);
        if (! $company) {
            return;
        }
        $to = $conv->assigned_to ? [$conv->assigned_to] : User::where('company_id', $company->id)->where('is_active', true)->get()
            ->filter(fn ($u) => $u->hasPermission('social.inbox') || $u->hasPermission('social.manage'))->pluck('id')->all();
        $who = $msg['author'] ?? $conv->author_name ?? 'Quelqu\'un';
        AppNotification::send($company->id, $to, 'social', ($kind === 'message' ? 'Message ' : 'Commentaire ').Networks::label($acc->network).' : '.Str::limit((string) $who, 40),
            Str::limit((string) ($msg['text'] ?? ''), 160), 'social', 'inbox:'.$conv->id);
        if ($kind === 'comment' && ! empty($msg['external_id'])) {
            $this->rules($company, $acc, $conv, $msg);
        }
    }

    /** Réponses automatiques aux commentaires contenant un mot-clé (ex. « prix » : réponse publique + message privé). */
    protected function rules(Company $company, object $acc, object $conv, array $msg): void
    {
        $text = mb_strtolower((string) ($msg['text'] ?? ''));
        foreach ((array) $company->setting('social_inbox_rules', []) as $rule) {
            $kw = array_filter(array_map(fn ($k) => mb_strtolower(trim($k)), explode(',', (string) ($rule['keywords'] ?? ''))));
            if (! $kw || ! collect($kw)->contains(fn ($k) => str_contains($text, $k))) {
                continue;
            }
            $vars = ['{{prenom}}' => explode(' ', trim((string) ($msg['author'] ?? '')))[0] ?? '', '{{nom}}' => (string) ($msg['author'] ?? ''), '{{entreprise}}' => $company->name];
            rescue(function () use ($rule, $vars, $acc, $conv, $msg) {
                if (! empty($rule['public'])) {
                    $this->replyComment($acc, $conv, (string) $msg['external_id'], strtr((string) $rule['public'], $vars), null);
                }
                if (! empty($rule['private']) && $acc->network === 'facebook') {
                    $this->privateReply($acc, $conv, (string) $msg['external_id'], strtr((string) $rule['private'], $vars), null);
                }
            }, null, false);

            return;
        }
    }

    /** Événements du webhook Meta (page ou compte Instagram). */
    public function webhook(object $acc, array $entry): int
    {
        $n = 0;
        $self = (string) $acc->external_id;
        $page = self::pageId($acc);
        foreach ((array) ($entry['changes'] ?? []) as $ch) {
            $v = (array) ($ch['value'] ?? []);
            if ($acc->network === 'facebook' && ($ch['field'] ?? '') === 'feed' && ($v['item'] ?? '') === 'comment' && ($v['verb'] ?? '') === 'add') {
                $thread = ($v['parent_id'] ?? '') && ($v['parent_id'] ?? '') !== ($v['post_id'] ?? '') ? (string) $v['parent_id'] : (string) $v['comment_id'];
                $mine = (string) ($v['from']['id'] ?? '') === $page;
                $n += (int) $this->add($acc, 'comment', $thread, ['external_id' => $v['comment_id'], 'direction' => $mine ? 'out' : 'in', 'author' => $v['from']['name'] ?? null, 'text' => $v['message'] ?? null,
                    'at' => isset($v['created_time']) ? date('Y-m-d H:i:s', is_numeric($v['created_time']) ? (int) $v['created_time'] : strtotime($v['created_time'])) : now()],
                    $mine ? ['post_ref' => $v['post_id'] ?? null] : ['post_ref' => $v['post_id'] ?? null, 'author_ref' => $v['from']['id'] ?? null, 'author_name' => $v['from']['name'] ?? null]);
            }
            if ($acc->network === 'instagram' && ($ch['field'] ?? '') === 'comments') {
                $thread = (string) ($v['parent_id'] ?? $v['id'] ?? '');
                $mine = (string) ($v['from']['id'] ?? '') === $self;
                if ($thread !== '') {
                    $n += (int) $this->add($acc, 'comment', $thread, ['external_id' => $v['id'] ?? null, 'direction' => $mine ? 'out' : 'in', 'author' => $v['from']['username'] ?? null, 'text' => $v['text'] ?? null],
                        $mine ? ['post_ref' => $v['media']['id'] ?? null] : ['post_ref' => $v['media']['id'] ?? null, 'author_ref' => $v['from']['id'] ?? null, 'author_name' => $v['from']['username'] ?? null]);
                }
            }
        }
        foreach ((array) ($entry['messaging'] ?? []) as $m) {
            if (empty($m['message']) || ! empty($m['message']['is_deleted'])) {
                continue;
            }
            $sender = (string) ($m['sender']['id'] ?? '');
            $echo = ! empty($m['message']['is_echo']) || $sender === $self || $sender === $page;
            $other = $echo ? (string) ($m['recipient']['id'] ?? '') : $sender;
            if ($other === '') {
                continue;
            }
            $name = $echo ? null : $this->personName($acc, $other);
            $text = $m['message']['text'] ?? (! empty($m['message']['attachments']) ? '('.($m['message']['attachments'][0]['type'] ?? 'pièce jointe').')' : null);
            $n += (int) $this->add($acc, 'message', $other, ['external_id' => $m['message']['mid'] ?? null, 'direction' => $echo ? 'out' : 'in', 'author' => $name, 'text' => $text,
                'at' => isset($m['timestamp']) ? date('Y-m-d H:i:s', (int) ($m['timestamp'] / 1000)) : now()], $echo ? [] : ['author_ref' => $other, 'author_name' => $name]);
        }

        return $n;
    }

    protected function personName(object $acc, string $id): ?string
    {
        $known = DB::table('so_conversations')->where('account_id', $acc->id)->where('author_ref', $id)->whereNotNull('author_name')->value('author_name');
        if ($known) {
            return $known;
        }

        return rescue(function () use ($acc, $id) {
            $r = Http::timeout(10)->get($this->graph().'/'.$id, ['fields' => $acc->network === 'instagram' ? 'name,username' : 'name', 'access_token' => $this->oauth->token($acc)]);

            return $r->ok() ? ($r->json('name') ?: $r->json('username')) : null;
        }, null, false);
    }

    // ------------------------------------------------------------------ relevé régulier (sans webhook)

    public function poll(Company $c): int
    {
        $n = 0;
        foreach (DB::table('so_accounts')->where('company_id', $c->id)->whereIn('network', self::NETWORKS)->where('inbox_enabled', true)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('inbox_polled_at')->orWhere('inbox_polled_at', '<=', now()->subMinutes(9)))->get() as $acc) {
            try {
                $n += $acc->network === 'facebook' ? $this->pollFacebook($acc) : $this->pollInstagram($acc);
                DB::table('so_accounts')->where('id', $acc->id)->update(['inbox_polled_at' => now()]);
            } catch (\Throwable $e) {
                DB::table('so_accounts')->where('id', $acc->id)->update(['inbox_polled_at' => now(), 'last_error' => Str::limit('Boîte de réception : '.$e->getMessage(), 490, '')]);
            }
        }

        return $n;
    }

    protected function pollFacebook(object $acc): int
    {
        $tok = $this->oauth->token($acc);
        $first = $acc->inbox_polled_at === null; // premier relevé : historique récent sans notification
        $n = 0;
        $feed = Http::timeout(30)->get($this->graph().'/'.$acc->external_id.'/feed', ['fields' => 'id,message,comments.limit(30).order(reverse_chronological){id,message,created_time,from,comments.limit(10){id,message,created_time,from}}',
            'since' => now()->subDays(5)->timestamp, 'limit' => 15, 'access_token' => $tok]);
        $this->check($feed);
        foreach ((array) $feed->json('data') as $post) {
            foreach ((array) ($post['comments']['data'] ?? []) as $cm) {
                $all = array_merge([$cm], (array) ($cm['comments']['data'] ?? []));
                foreach ($all as $x) {
                    $mine = (string) ($x['from']['id'] ?? '') === (string) $acc->external_id;
                    $n += (int) $this->add($acc, 'comment', (string) $cm['id'], ['external_id' => $x['id'], 'direction' => $mine ? 'out' : 'in', 'author' => $x['from']['name'] ?? null, 'text' => $x['message'] ?? null,
                        'at' => date('Y-m-d H:i:s', strtotime($x['created_time'] ?? 'now')), 'quiet' => $first], ['post_ref' => $post['id'], 'post_text' => Str::limit((string) ($post['message'] ?? ''), 290, '') ?: null,
                            'author_ref' => $cm['from']['id'] ?? null, 'author_name' => $cm['from']['name'] ?? null]);
                }
            }
        }
        $n += $this->pollMessages($acc, $tok, 'messenger', $first);

        return $n;
    }

    protected function pollInstagram(object $acc): int
    {
        $tok = $this->oauth->token($acc);
        $first = $acc->inbox_polled_at === null;
        $n = 0;
        $media = Http::timeout(30)->get($this->graph().'/'.$acc->external_id.'/media', ['fields' => 'id,caption,comments.limit(30){id,text,username,timestamp,from,replies{id,text,username,timestamp,from}}', 'limit' => 12, 'access_token' => $tok]);
        $this->check($media);
        foreach ((array) $media->json('data') as $p) {
            foreach ((array) ($p['comments']['data'] ?? []) as $cm) {
                foreach (array_merge([$cm], (array) ($cm['replies']['data'] ?? [])) as $x) {
                    $mine = (string) ($x['from']['id'] ?? '') === (string) $acc->external_id || ($x['username'] ?? null) === ltrim((string) $acc->username, '@');
                    $n += (int) $this->add($acc, 'comment', (string) $cm['id'], ['external_id' => $x['id'], 'direction' => $mine ? 'out' : 'in', 'author' => $x['username'] ?? null, 'text' => $x['text'] ?? null,
                        'at' => date('Y-m-d H:i:s', strtotime($x['timestamp'] ?? 'now')), 'quiet' => $first], ['post_ref' => $p['id'], 'post_text' => Str::limit((string) ($p['caption'] ?? ''), 290, '') ?: null,
                            'author_ref' => $cm['from']['id'] ?? null, 'author_name' => $cm['username'] ?? null]);
                }
            }
        }
        $n += $this->pollMessages($acc, $tok, 'instagram', $first);

        return $n;
    }

    protected function pollMessages(object $acc, string $tok, string $platform, bool $first): int
    {
        $page = self::pageId($acc);
        $r = Http::timeout(30)->get($this->graph().'/'.$page.'/conversations', ['platform' => $platform, 'fields' => 'participants,updated_time,messages.limit(10){id,message,created_time,from}', 'limit' => 20, 'access_token' => $tok]);
        if (! $r->ok()) {
            return 0; // autorisation des messages pas encore accordée : seuls les commentaires sont relevés
        }
        $self = $platform === 'instagram' ? (string) $acc->external_id : $page;
        $n = 0;
        foreach ((array) $r->json('data') as $conv) {
            $other = collect((array) ($conv['participants']['data'] ?? []))->first(fn ($p) => (string) ($p['id'] ?? '') !== $self && (string) ($p['id'] ?? '') !== $page);
            if (! $other) {
                continue;
            }
            $name = $other['name'] ?? $other['username'] ?? null;
            foreach (array_reverse((array) ($conv['messages']['data'] ?? [])) as $m) {
                $out = in_array((string) ($m['from']['id'] ?? ''), [$self, $page], true);
                $n += (int) $this->add($acc, 'message', (string) $other['id'], ['external_id' => $m['id'], 'direction' => $out ? 'out' : 'in', 'author' => $out ? null : $name, 'text' => $m['message'] ?? null,
                    'at' => date('Y-m-d H:i:s', strtotime($m['created_time'] ?? 'now')), 'quiet' => $first], ['author_ref' => $other['id'], 'author_name' => $name]);
            }
        }

        return $n;
    }

    // ------------------------------------------------------------------ actions

    protected function account(object $conv): object
    {
        return DB::table('so_accounts')->where('id', $conv->account_id)->first() ?: $this->fail('Compte du réseau introuvable.');
    }

    /** Réponse dans la conversation : commentaire public ou message privé selon le fil. */
    public function reply(object $conv, string $text, ?User $u): void
    {
        $acc = $this->account($conv);
        if ($conv->kind === 'message') {
            $this->sendMessage($acc, $conv, ['id' => $conv->thread_ref], $text, $u, false);

            return;
        }
        $this->replyComment($acc, $conv, $conv->thread_ref, $text, $u);
    }

    public function replyComment(object $acc, object $conv, string $commentId, string $text, ?User $u): void
    {
        $url = $acc->network === 'instagram' ? $this->graph().'/'.$commentId.'/replies' : $this->graph().'/'.$commentId.'/comments';
        $r = Http::timeout(20)->asForm()->post($url, ['message' => $text, 'access_token' => $this->oauth->token($acc)]);
        $this->check($r);
        $this->add($acc, 'comment', $conv->thread_ref, ['external_id' => $r->json('id'), 'direction' => 'out', 'author' => $u?->name ?? $acc->name, 'text' => $text, 'sent_by' => $u?->id]);
    }

    /** Message privé à l'auteur d'un commentaire (une seule réponse privée par commentaire, dans les 7 jours). */
    public function privateReply(object $acc, object $conv, string $commentId, string $text, ?User $u): void
    {
        $this->sendMessage($acc, $conv, ['comment_id' => $commentId], $text, $u, true);
    }

    protected function sendMessage(object $acc, object $conv, array $recipient, string $text, ?User $u, bool $private): void
    {
        $r = Http::timeout(20)->post($this->graph().'/'.self::pageId($acc).'/messages', ['recipient' => $recipient, 'messaging_type' => 'RESPONSE', 'message' => ['text' => $text], 'access_token' => $this->oauth->token($acc)]);
        $this->check($r);
        $this->add($acc, $conv->kind, $conv->thread_ref, ['external_id' => $r->json('message_id'), 'direction' => 'out', 'author' => $u?->name ?? $acc->name, 'text' => $text, 'sent_by' => $u?->id, 'private' => $private]);
    }

    public function hide(object $conv, int $messageId, bool $hide = true): void
    {
        $acc = $this->account($conv);
        $m = DB::table('so_messages')->where('conversation_id', $conv->id)->where('id', $messageId)->first() ?: $this->fail('Commentaire introuvable.');
        if ($conv->kind !== 'comment' || ! $m->external_id) {
            $this->fail('Seuls les commentaires peuvent être masqués.');
        }
        $r = Http::timeout(20)->asForm()->post($this->graph().'/'.$m->external_id, [$acc->network === 'instagram' ? 'hide' : 'is_hidden' => $hide ? 'true' : 'false', 'access_token' => $this->oauth->token($acc)]);
        $this->check($r);
        DB::table('so_messages')->where('id', $m->id)->update(['hidden' => $hide, 'updated_at' => now()]);
    }

    /** Abonne la page aux événements (commentaires, messages, prospects des publicités) et active la boîte de réception. */
    public function enable(object $acc): array
    {
        $page = self::pageId($acc);
        $fields = 'feed,messages,message_echoes'.(SocialOAuth::metaExtended() ? ',leadgen' : '');
        $r = Http::timeout(20)->asForm()->post($this->graph().'/'.$page.'/subscribed_apps', ['subscribed_fields' => $fields, 'access_token' => $this->oauth->token($acc)]);
        $webhook = $r->ok();
        DB::table('so_accounts')->where('id', $acc->id)->update(['inbox_enabled' => true, 'inbox_polled_at' => null, 'updated_at' => now()]);

        return ['webhook' => $webhook, 'message' => $webhook ? 'Commentaires, messages'.(SocialOAuth::metaExtended() ? ' et prospects des publicités' : '').' reçus en direct.'
            : 'Activée : les commentaires sont relevés toutes les 10 minutes. Pour le direct et les messages privés, l\'application Meta doit avoir les autorisations validées ('.Str::limit((string) ($r->json('error.message') ?? ''), 120).').'];
    }

    public function toLead(Company $c, object $conv, ?User $u): string
    {
        if ($conv->lead_id && DB::table('crm_leads')->where('id', $conv->lead_id)->exists()) {
            return $conv->lead_id;
        }
        if (! $c->hasModule('crm')) {
            $this->fail('Le module CRM n\'est pas activé.');
        }
        $msgs = DB::table('so_messages')->where('conversation_id', $conv->id)->orderBy('sent_at')->limit(20)->get();
        $lead = \App\Modules\Crm\Models\Lead::create([
            'title' => Str::limit(Networks::label($conv->network).' — '.($conv->author_name ?: 'contact'), 250, ''), 'contact_name' => $conv->author_name, 'source' => 'social', 'owner_id' => $conv->assigned_to ?? $u?->id,
            'notes' => Str::limit($msgs->map(fn ($m) => ($m->direction === 'in' ? ($m->author_name ?: 'Client') : 'Nous').' : '.$m->text)->implode("\n"), 10000, ''),
            'utm_source' => $conv->network, 'utm_medium' => 'social',
        ]);
        DB::table('so_conversations')->where('id', $conv->id)->update(['lead_id' => $lead->id, 'updated_at' => now()]);

        return $lead->id;
    }
}
