<?php

namespace App\Modules\Whatsapp;

use App\Messaging\Message;
use App\Messaging\Phone;
use App\Messaging\Providers\WhatsApp;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\MessagingChannel;
use App\Models\User;
use App\Modules\Whatsapp\Models\Conversation;
use App\Modules\Whatsapp\Models\WaMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Boîte de discussion WhatsApp partagée : réception (webhooks Meta, UltraMsg, Green API), envoi, statuts de lecture,
 * modèles approuvés, attribution, messages d'accueil et d'absence.
 */
class Inbox
{
    public const META_VERSION = 'v21.0';

    public function settings(Company $c): object
    {
        $row = DB::table('wa_settings')->where('company_id', $c->id)->first();
        if (! $row) {
            DB::table('wa_settings')->insert(['company_id' => $c->id, 'webhook_token' => Str::random(40), 'verify_token' => Str::random(24), 'auto_assign' => true,
                'hours' => json_encode(['1' => ['08:00', '18:00'], '2' => ['08:00', '18:00'], '3' => ['08:00', '18:00'], '4' => ['08:00', '18:00'], '5' => ['08:00', '18:00'], '6' => ['09:00', '13:00'], '7' => null]),
                'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('wa_settings')->where('company_id', $c->id)->first();
        }

        return $row;
    }

    /** @return array{provider: ?string, config: array} */
    public function channel(Company $c): array
    {
        $row = MessagingChannel::withoutGlobalScopes()->where(['company_id' => $c->id, 'channel' => 'whatsapp'])->first();

        return ['provider' => $row && $row->enabled ? $row->provider : null, 'config' => $row ? (array) $row->settings : []];
    }

    public static function preview(string $type, ?string $body, ?string $file = null): string
    {
        $icon = ['image' => 'Photo', 'document' => 'Document '.($file ?: ''), 'audio' => 'Note vocale', 'video' => 'Vidéo', 'location' => 'Position', 'sticker' => 'Autocollant', 'contacts' => 'Contact'][$type] ?? null;
        $icon = $icon ? trim($icon) : null;

        return mb_substr(trim(($icon ? $icon.($body ? ' · ' : '') : '').(string) $body), 0, 300);
    }

    public function conversation(Company $c, string $phone, ?string $name = null): Conversation
    {
        $conv = Conversation::withoutGlobalScopes()->firstOrNew(['company_id' => $c->id, 'phone' => $phone]);
        if (! $conv->exists) {
            $conv->company_id = $c->id;
            $conv->status = 'open';
            $conv->partner_id = $this->partnerFor($c, $phone);
            if ($conv->partner_id) { // le nom de la fiche client prime sur le nom du profil WhatsApp
                $conv->name = DB::table('partners')->where('id', $conv->partner_id)->value('name');
            }
        }
        if ($name && (! $conv->name || $conv->name === $phone)) {
            $conv->name = mb_substr($name, 0, 190);
        }
        $conv->name ??= '+'.$phone;
        $conv->save();

        return $conv;
    }

    /** Fiche client dont le téléphone correspond (8 derniers chiffres, puis numéro complet). */
    public function partnerFor(Company $c, string $phone): ?string
    {
        $cc = strlen($phone) > 10 ? substr($phone, 0, strlen($phone) - 10) : '225';
        $rows = DB::table('partners')->where('company_id', $c->id)->whereNull('deleted_at')->whereNotNull('phone')
            ->whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '.', ''), '-', ''), '/', '') LIKE ?", ['%'.substr($phone, -8)])->limit(20)->get(['id', 'phone']);
        foreach ($rows as $p) {
            if (Phone::digits($p->phone, $cc ?: '225') === $phone) {
                return $p->id;
            }
        }

        return $rows->first()?->id;
    }

    // ------------------------------------------------------------------ réception

    /**
     * Message reçu, normalisé : phone, name, id, type, body, media (callable → [bytes, mime, name]) , at.
     */
    public function receive(Company $c, array $in): ?WaMessage
    {
        if (! empty($in['id']) && WaMessage::withoutGlobalScopes()->where('company_id', $c->id)->where('provider_id', $in['id'])->exists()) {
            return null; // déjà reçu (le fournisseur renvoie parfois)
        }
        $conv = $this->conversation($c, $in['phone'], $in['name'] ?? null);
        $isNew = ! $conv->last_message_at;
        $path = $mime = $file = null;
        if (! empty($in['media'])) {
            try {
                [$bytes, $mime, $file] = ($in['media'])();
                if ($bytes !== null && strlen($bytes) <= 25 * 1024 * 1024) {
                    $path = $this->storeFile($c, $bytes, $mime, $file);
                }
            } catch (\Throwable $e) {
                $in['body'] = trim(($in['body'] ?? '').' [fichier non récupéré : '.mb_substr($e->getMessage(), 0, 120).']');
            }
        }
        $m = new WaMessage(['conversation_id' => $conv->id, 'direction' => 'in', 'type' => $in['type'] ?? 'text', 'body' => isset($in['body']) ? mb_substr((string) $in['body'], 0, 10000) : null,
            'media_path' => $path, 'media_mime' => $mime, 'file_name' => $file ? mb_substr($file, 0, 190) : null, 'provider_id' => $in['id'] ?? null, 'status' => 'received']);
        $m->company_id = $c->id;
        $at = $in['at'] ?? now();
        $m->created_at = $at;
        $m->save();
        $conv->forceFill([
            'unread' => $conv->unread + 1, 'last_preview' => self::preview($m->type, $m->body, $m->file_name), 'last_direction' => 'in', 'last_message_at' => $at, 'last_inbound_at' => $at,
            'status' => $conv->status === 'closed' ? 'open' : $conv->status,
        ])->save();
        $s = $this->settings($c);
        if (! $conv->assigned_to && $s->auto_assign) {
            $this->autoAssign($c, $conv);
        }
        $this->notify($c, $conv, $m);
        // réponses automatiques : accueil (nouvelle conversation), absence (hors horaires, une fois par 12 h)
        try {
            if ($isNew && trim((string) $s->welcome_message) !== '') {
                $this->send($conv, null, ['body' => $this->vars($s->welcome_message, $c, $conv)], true);
                $conv->forceFill(['welcome_sent_at' => now()])->save();
            } elseif (trim((string) $s->away_message) !== '' && ! $this->isOpen($s) && (! $conv->away_sent_at || $conv->away_sent_at->lt(now()->subHours(12)))) {
                $this->send($conv, null, ['body' => $this->vars($s->away_message, $c, $conv)], true);
                $conv->forceFill(['away_sent_at' => now()])->save();
            }
        } catch (\Throwable) {
        }

        return $m;
    }

    protected function vars(string $text, Company $c, Conversation $conv): string
    {
        return strtr($text, ['{nom}' => $conv->name && ! str_starts_with($conv->name, '+') ? $conv->name : '', '{societe}' => $c->name, '{horaires}' => $this->hoursText($this->settings($c))]);
    }

    public function isOpen(object $s, ?Carbon $at = null): bool
    {
        $at ??= now();
        $h = json_decode((string) $s->hours, true) ?: [];
        $d = $h[(string) $at->isoWeekday()] ?? null;
        if (! $d || count($d) < 2) {
            return false;
        }

        return $at->format('H:i') >= $d[0] && $at->format('H:i') < $d[1];
    }

    public function hoursText(object $s): string
    {
        $names = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
        $h = json_decode((string) $s->hours, true) ?: [];
        $parts = [];
        foreach ($names as $i => $n) {
            if (! empty($h[(string) $i])) {
                $parts[] = $n.' '.str_replace(':', ' h ', $h[(string) $i][0]).' – '.str_replace(':', ' h ', $h[(string) $i][1]);
            }
        }

        return implode(', ', $parts);
    }

    /** À tour de rôle entre les utilisateurs qui ont accès à WhatsApp. */
    public function autoAssign(Company $c, Conversation $conv): void
    {
        $agents = User::where('company_id', $c->id)->where('is_active', true)->orderBy('id')->get()->filter(fn ($u) => $u->hasPermission('whatsapp.use') && ! $u->is_super_admin)->values();
        if ($agents->isEmpty()) {
            return;
        }
        $s = $this->settings($c);
        $agent = $agents[$s->rr_cursor % $agents->count()];
        DB::table('wa_settings')->where('id', $s->id)->update(['rr_cursor' => $s->rr_cursor + 1]);
        $conv->forceFill(['assigned_to' => $agent->id])->save();
    }

    protected function notify(Company $c, Conversation $conv, WaMessage $m): void
    {
        $users = $conv->assigned_to ? [$conv->assigned_to] : User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('whatsapp.manage'))->pluck('id')->all();
        try {
            AppNotification::send($c->id, $users, 'whatsapp', 'WhatsApp · '.$conv->name, self::preview($m->type, $m->body, $m->file_name), 'whatsapp', (string) $conv->id);
        } catch (\Throwable) {
        }
    }

    public function status(Company $c, string $providerId, string $status, ?string $error = null): void
    {
        $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
        $m = WaMessage::withoutGlobalScopes()->where('company_id', $c->id)->where('provider_id', $providerId)->first();
        if ($m && ($status === 'failed' || ($rank[$status] ?? 0) > ($rank[$m->status] ?? 0))) {
            $m->forceFill(['status' => $status, 'error' => $error ? mb_substr($error, 0, 500) : $m->error])->save();
        }
    }

    // ------------------------------------------------------------------ envoi

    /** Extensions acceptées pour les pièces (images, documents, audio, vidéo). */
    public const SAFE_EXT = ['jpg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'csv', 'zip',
        'mp3', 'ogg', 'oga', 'opus', 'm4a', 'aac', 'amr', 'wav', 'mp4', '3gp', 'mov', 'webm'];

    public function storeFile(Company $c, string $bytes, ?string $mime, ?string $name): string
    {
        $ext = strtolower(pathinfo((string) $name, PATHINFO_EXTENSION)) ?: (explode('/', explode(';', (string) $mime)[0])[1] ?? 'bin');
        $ext = preg_replace('/[^a-z0-9]/', '', $ext === 'jpeg' ? 'jpg' : $ext) ?: 'bin';
        // jamais de page web, de script ou de SVG servis depuis le domaine de l'application
        if (! in_array($ext, self::SAFE_EXT, true)) {
            $ext = 'bin';
        }
        $path = 'whatsapp/'.$c->id.'/'.Str::random(40).'.'.$ext;
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    public static function publicUrl(string $path): string
    {
        return url('/whatsapp/fichier/'.basename($path));
    }

    /**
     * Envoie un message dans la conversation. $d : body, file [bytes, mime, name], template, params.
     * Hors fenêtre de 24 h (API Cloud de Meta), seul un modèle approuvé est accepté.
     */
    public function send(Conversation $conv, ?int $userId, array $d, bool $auto = false): WaMessage
    {
        $c = Company::find($conv->company_id);
        ['provider' => $provider, 'config' => $cfg] = $this->channel($c);
        if (! $provider || ! $c->hasModule('whatsapp')) {
            throw new \DomainException('WhatsApp n\'est pas configuré : choisissez un fournisseur dans Paramètres › Canaux d\'envoi.');
        }
        $template = $d['template'] ?? null;
        if ($provider === 'meta' && ! $template && ! $conv->windowOpen()) {
            throw new \DomainException('Le client n\'a pas écrit depuis plus de 24 h : WhatsApp impose un modèle approuvé pour relancer la conversation.');
        }
        $path = $mime = $name = null;
        if (! empty($d['file'])) {
            [$bytes, $mime, $name] = $d['file'];
            $path = $this->storeFile($c, $bytes, $mime, $name);
        }
        $type = $template ? 'template' : ($path ? (str_starts_with((string) $mime, 'image/') ? 'image' : 'document') : 'text');
        $body = $d['body'] ?? null;
        if ($template && ! $body) {
            $tpl = DB::table('wa_templates')->where('company_id', $c->id)->where('name', $template)->first();
            $body = $tpl ? preg_replace_callback('/\{\{(\d+)\}\}/', fn ($x) => (string) (($d['params'] ?? [])[(int) $x[1] - 1] ?? $x[0]), (string) $tpl->body) : $template;
        }
        $m = new WaMessage(['conversation_id' => $conv->id, 'direction' => 'out', 'type' => $type, 'body' => $body, 'media_path' => $path, 'media_mime' => $mime, 'file_name' => $name,
            'status' => 'queued', 'user_id' => $userId, 'template' => $template, 'auto' => $auto]);
        $m->company_id = $c->id;
        $m->save();
        $msg = new Message(to: $conv->phone, text: (string) ($template ? ($d['body'] ?? '') : $body), mediaUrl: $path ? self::publicUrl($path) : null, template: $template,
            templateParams: array_values($d['params'] ?? []), mediaName: $name, mediaMime: $mime);
        $cfg['template_language'] = $d['language'] ?? ($cfg['template_language'] ?? 'fr');
        $r = (new WhatsApp($provider))->send($msg, $cfg + ['country_code' => '225']);
        $m->forceFill(['status' => $r->ok ? 'sent' : 'failed', 'provider_id' => $r->id ? mb_substr((string) $r->id, 0, 190) : null, 'error' => $r->ok ? null : mb_substr((string) $r->error, 0, 500)])->save();
        $conv->forceFill(['last_preview' => self::preview($type, $body, $name), 'last_direction' => 'out', 'last_message_at' => now(),
            'status' => $conv->status === 'closed' && ! $auto ? 'open' : $conv->status] + ($auto ? [] : ['unread' => 0]))->save();
        if (! $r->ok && ! $auto) {
            throw new \DomainException((string) $r->error);
        }

        return $m;
    }

    // ------------------------------------------------------------------ modèles (API Cloud de Meta)

    public function syncTemplates(Company $c): int
    {
        ['provider' => $provider, 'config' => $cfg] = $this->channel($c);
        if ($provider !== 'meta' || empty($cfg['waba_id'])) {
            throw new \DomainException('Les modèles se synchronisent avec l\'API Cloud de Meta : renseignez le « WhatsApp Business Account ID » dans Paramètres › Canaux d\'envoi.');
        }
        $url = 'https://graph.facebook.com/'.self::META_VERSION.'/'.$cfg['waba_id'].'/message_templates?limit=200&fields=name,language,status,category,components';
        $seen = [];
        for ($i = 0; $url && $i < 10; $i++) {
            $r = Http::withToken($cfg['access_token'] ?? '')->acceptJson()->timeout(30)->get($url);
            if ($r->failed()) {
                throw new \DomainException('Meta : '.($r->json('error.message') ?: 'erreur '.$r->status()));
            }
            foreach ($r->json('data') ?? [] as $t) {
                $bodyText = collect($t['components'] ?? [])->firstWhere('type', 'BODY')['text'] ?? '';
                preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $mm);
                DB::table('wa_templates')->updateOrInsert(['company_id' => $c->id, 'name' => $t['name'], 'language' => $t['language'] ?? 'fr'], [
                    'category' => $t['category'] ?? null, 'status' => $t['status'] ?? 'APPROVED', 'body' => $bodyText, 'params' => $mm[1] ? max(array_map('intval', $mm[1])) : 0,
                    'components' => json_encode($t['components'] ?? []), 'updated_at' => now(), 'created_at' => now()]);
                $seen[] = $t['name'].'|'.($t['language'] ?? 'fr');
            }
            $url = $r->json('paging.next');
        }
        DB::table('wa_templates')->where('company_id', $c->id)->get()->each(function ($t) use ($seen) {
            if (! in_array($t->name.'|'.$t->language, $seen, true)) {
                DB::table('wa_templates')->where('id', $t->id)->delete();
            }
        });

        return count($seen);
    }

    /** Propose un nouveau modèle à Meta (validation sous 24 h en général). */
    public function createTemplate(Company $c, string $name, string $category, string $body, string $language = 'fr', array $examples = []): void
    {
        ['provider' => $provider, 'config' => $cfg] = $this->channel($c);
        if ($provider !== 'meta' || empty($cfg['waba_id'])) {
            throw new \DomainException('La création de modèles passe par l\'API Cloud de Meta (WhatsApp Business Account ID requis).');
        }
        $name = trim(preg_replace('/[^a-z0-9_]+/', '_', Str::ascii(mb_strtolower($name))), '_');
        preg_match_all('/\{\{(\d+)\}\}/', $body, $mm);
        $n = $mm[1] ? max(array_map('intval', $mm[1])) : 0;
        $comp = ['type' => 'BODY', 'text' => $body];
        if ($n > 0) {
            $comp['example'] = ['body_text' => [array_map(fn ($i) => (string) ($examples[$i] ?? 'exemple '.($i + 1)), range(0, $n - 1))]];
        }
        $r = Http::withToken($cfg['access_token'] ?? '')->acceptJson()->timeout(30)->post('https://graph.facebook.com/'.self::META_VERSION.'/'.$cfg['waba_id'].'/message_templates', [
            'name' => $name, 'language' => $language, 'category' => $category, 'components' => [$comp]]);
        if ($r->failed()) {
            throw new \DomainException('Meta a refusé le modèle : '.($r->json('error.error_user_msg') ?: $r->json('error.message') ?: 'erreur '.$r->status()));
        }
        DB::table('wa_templates')->updateOrInsert(['company_id' => $c->id, 'name' => $name, 'language' => $language], [
            'category' => $category, 'status' => $r->json('status') ?: 'PENDING', 'body' => $body, 'params' => $n, 'components' => json_encode([$comp]), 'updated_at' => now(), 'created_at' => now()]);
    }
}
