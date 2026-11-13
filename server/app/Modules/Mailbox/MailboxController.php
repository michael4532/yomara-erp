<?php

namespace App\Modules\Mailbox;

use App\Http\Controllers\Controller;
use App\Modules\Mailbox\Drivers\GraphDriver;
use App\Modules\Mailbox\Drivers\ImapDriver;
use App\Modules\Mailbox\Drivers\Pop3Driver;
use App\Modules\Mailbox\Models\Account;
use App\Modules\Mailbox\Models\Folder;
use App\Modules\Mailbox\Models\Message;
use App\Modules\Mailbox\Protocol\MailProtocolException;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class MailboxController extends Controller
{
    public function __construct(protected MailboxService $svc) {}

    protected function canManage(Request $r): bool
    {
        return $r->user()->hasPermission('mailbox.manage');
    }

    protected function account(Request $r, int $id, bool $write = false): Account
    {
        $a = Account::findOrFail($id);
        $ok = $a->readableBy($r->user()->id) || ($a->user_id === null && $this->canManage($r));
        abort_unless($ok, 404);
        if ($write) {
            abort_unless($a->user_id === $r->user()->id || ($a->user_id === null && $this->canManage($r)), 403, 'Seul le propriétaire ou un gestionnaire peut modifier ce compte.');
        }

        return $a;
    }

    protected function message(Request $r, int $id): Message
    {
        $m = Message::findOrFail($id);
        abort_unless($m->account && $m->account->readableBy($r->user()->id), 404);

        return $m;
    }

    protected function accountIds(Request $r): array
    {
        return Account::forUser($r->user()->id)->pluck('id')->all();
    }

    public static function accountRow(Account $a): array
    {
        return ['id' => $a->id, 'avatar_url' => $a->avatar_url, 'provider' => $a->provider, 'provider_label' => Account::PROVIDERS[$a->provider] ?? $a->provider, 'email' => $a->email, 'display_name' => $a->display_name,
            'color' => $a->color, 'shared' => $a->user_id === null, 'shared_with' => array_map('intval', $a->shared_with ?? []), 'user_id' => $a->user_id, 'status' => $a->status, 'error' => $a->error,
            'sync_days' => $a->sync_days, 'calendar_sync' => $a->calendar_sync, 'last_sync_at' => $a->last_sync_at?->toIso8601String(),
            'settings' => array_intersect_key($a->settings ?? [], array_flip(['imap_host', 'imap_port', 'imap_security', 'smtp_host', 'smtp_port', 'smtp_security', 'pop3_host', 'pop3_port', 'pop3_security', 'username', 'smtp_username', 'pop_keep', 'graph_user'])),
            'has_password' => ! $a->isOAuth() && ! empty($a->secret()['password']), 'hosted' => $a->setting('hosted') === true, 'limits' => Antispam::limits($a),
            'send_blocked_until' => ($u = $a->stateValue('send_blocked_until')) && $u > time() ? date('c', $u) : null];
    }

    public static function folderRow(Folder $f): array
    {
        return ['id' => $f->id, 'account_id' => $f->account_id, 'name' => $f->name, 'role' => $f->role, 'unread' => $f->unread, 'total' => $f->total, 'sync' => $f->sync,
            'depth' => substr_count($f->name, ' › '), 'custom' => $f->role === 'other', 'error' => $f->state['error'] ?? null];
    }

    public static function messageRow(Message $m, bool $body = false): array
    {
        $row = ['id' => $m->id, 'account_id' => $m->account_id, 'folder_id' => $m->folder_id, 'thread_key' => $m->thread_key, 'subject' => $m->subject, 'from_name' => $m->from_name,
            'from_email' => $m->from_email, 'to' => $m->to ?? [], 'cc' => $m->cc ?? [], 'date' => $m->date?->format('Y-m-d H:i:s'), 'snippet' => $m->snippet, 'seen' => $m->seen,
            'flagged' => $m->flagged, 'answered' => $m->answered, 'draft' => $m->draft, 'has_attachments' => $m->has_attachments, 'partner_id' => $m->partner_id,
            'updated_at' => $m->updated_at?->format('Y-m-d H:i:s')];
        if ($body) {
            $row += ['bcc' => $m->bcc ?? [], 'reply_to' => $m->reply_to, 'message_id' => $m->message_id, 'body_loaded' => $m->body_loaded,
                'html' => self::withCid($m), 'text' => $m->body_text !== null ? mb_substr($m->body_text, 0, 200000) : null,
                'attachments' => array_map(fn ($x) => $x + ['url' => self::attUrl($m, (string) $x['id'])], array_values($m->attachments ?? []))];
        }

        return $row;
    }

    public static function attUrl(Message $m, string $attId, bool $inline = false): string
    {
        return URL::temporarySignedRoute('mailbox.attachment', now()->addHours(12), ['id' => $m->id, 'att' => rtrim(strtr(base64_encode($attId), '+/', '-_'), '='), 'inline' => $inline ? 1 : 0]);
    }

    protected static function withCid(Message $m): ?string
    {
        $html = $m->body_html;
        if (! $html || ! str_contains($html, 'cid:')) {
            return $html;
        }
        foreach ($m->attachments ?? [] as $x) {
            if (! empty($x['cid'])) {
                $html = str_ireplace(['cid:'.$x['cid'].'"', "cid:{$x['cid']}'"], [self::attUrl($m, (string) $x['id'], true).'"', self::attUrl($m, (string) $x['id'], true)."'"], $html);
            }
        }

        return $html;
    }

    protected function fail(\Throwable $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    // ------------------------------------------------------------------ vue d'ensemble

    public function index(Request $r)
    {
        $c = app('tenant');
        $uid = $r->user()->id;
        $accounts = Account::forUser($uid)->orderByRaw('user_id is null')->orderBy('id')->get();
        if ($this->canManage($r)) { // le gestionnaire voit aussi les boîtes partagées pour les attribuer
            $accounts = $accounts->merge(Account::whereNull('user_id')->whereNotIn('id', $accounts->pluck('id'))->get());
        }

        return ['data' => [
            'accounts' => $accounts->map(fn ($a) => self::accountRow($a))->values(),
            'folders' => self::sortedFolders(Folder::whereIn('account_id', $accounts->pluck('id'))->get())->map(fn ($f) => self::folderRow($f))->values(),
            'signatures' => DB::table('mb_signatures')->where('company_id', $c->id)->where(fn ($q) => $q->where('user_id', $uid)->orWhereNull('user_id'))->orderBy('id')->get()
                ->map(fn ($s) => self::sigRow($s))->values(),
            'directory' => $this->directory($c),
            'signature_defaults' => DB::table('mb_signature_defaults')->where('user_id', $uid)->whereIn('account_id', $accounts->pluck('id'))->get(['account_id', 'new_id', 'reply_id'])
                ->map(fn ($x) => ['account_id' => (int) $x->account_id, 'new_id' => $x->new_id !== null ? (int) $x->new_id : null, 'reply_id' => $x->reply_id !== null ? (int) $x->reply_id : null])->values(),
            'templates' => DB::table('mb_templates')->where('company_id', $c->id)->where(fn ($q) => $q->where('user_id', $uid)->orWhereNull('user_id'))->orderBy('name')->get(),
            'outbox' => $this->outboxRows($r),
            'oauth' => ['microsoft' => OAuth::enabled('microsoft') && OAuth::configured($c, 'microsoft'), 'google' => OAuth::enabled('google') && OAuth::configured($c, 'google')],
            // Connexions coupées par le super admin : boutons masqués dans l'application
            'oauth_disabled' => ['microsoft' => ! OAuth::enabled('microsoft'), 'google' => ! OAuth::enabled('google')],
            'hosted' => HostedMail::summary($c),
            'mobile_url' => url('/application-mobile'),
            'can_manage' => $this->canManage($r),
            'users' => $this->canManage($r) ? DB::table('users')->where('company_id', $c->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']) : [],
        ]];
    }

    /** Copie locale de chaque poste : messages modifiés depuis le curseur (corps compris), suppressions. */
    public function changes(Request $r)
    {
        $ids = $this->accountIds($r);
        [$ts, $id] = array_pad(explode('|', (string) $r->query('since', '')), 2, 0);
        $limit = min(300, max(20, (int) $r->query('limit', 150)));
        $q = Message::withTrashed()->whereIn('account_id', $ids ?: [0]);
        if ($ts) {
            $q->where(fn ($w) => $w->where('updated_at', '>', $ts)->orWhere(fn ($w) => $w->where('updated_at', $ts)->where('id', '>', (int) $id)));
        } else { // premier chargement d'un poste : la fenêtre récente seulement
            $q->whereNull('deleted_at')->where('date', '>=', now()->subDays((int) min(90, max(7, (int) $r->query('days', 30)))));
        }
        $rows = $q->orderBy('updated_at')->orderBy('id')->limit($limit)->get();
        $out = [];
        $deleted = [];
        foreach ($rows as $m) {
            if ($m->deleted_at) {
                $deleted[] = $m->id;
            } else {
                $row = self::messageRow($m, true);
                if ($row['html'] && strlen($row['html']) > 400000) {
                    $row['html'] = null;
                    $row['body_loaded'] = false;
                }
                $out[] = $row;
            }
        }
        $last = $rows->last();

        return ['data' => $out, 'deleted' => $deleted, 'cursor' => $last ? $last->updated_at->format('Y-m-d H:i:s').'|'.$last->id : $r->query('since', ''), 'more' => $rows->count() === $limit];
    }

    public function messages(Request $r)
    {
        $ids = $this->accountIds($r);
        $q = Message::whereIn('account_id', $ids ?: [0]);
        if ($r->filled('account_id')) {
            $q->where('account_id', (int) $r->query('account_id'));
        }
        if ($r->filled('folder_id')) {
            $q->where('folder_id', (int) $r->query('folder_id'));
        } elseif ($r->filled('role')) {
            $q->whereIn('folder_id', Folder::whereIn('account_id', $ids ?: [0])->where('role', $r->query('role'))->pluck('id'));
        }
        if ($r->filled('partner_id')) {
            $q->where('partner_id', $r->query('partner_id'));
        }
        if ($r->filled('thread_key')) {
            $q->where('thread_key', $r->query('thread_key'));
        }
        if ($r->boolean('unread')) {
            $q->where('seen', false);
        }
        if ($r->boolean('flagged')) {
            $q->where('flagged', true);
        }
        if ($r->boolean('attachments')) {
            $q->where('has_attachments', true);
        }
        if ($s = trim((string) $r->query('q'))) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $s).'%';
            $q->where(fn ($w) => $w->where('subject', 'like', $like)->orWhere('from_email', 'like', $like)->orWhere('from_name', 'like', $like)
                ->orWhere('snippet', 'like', $like)->orWhere('body_text', 'like', $like)->orWhere('to', 'like', $like));
        }
        if ($r->filled('before')) {
            [$d, $bid] = array_pad(explode('|', $r->query('before')), 2, PHP_INT_MAX);
            $q->where(fn ($w) => $w->where('date', '<', $d)->orWhere(fn ($w) => $w->where('date', $d)->where('id', '<', (int) $bid)));
        }
        $limit = min(100, max(10, (int) $r->query('limit', 50)));
        $rows = $q->orderByDesc('date')->orderByDesc('id')->limit($limit)->get();
        $last = $rows->last();

        return ['data' => $rows->map(fn ($m) => self::messageRow($m))->values(), 'next' => $rows->count() === $limit && $last ? $last->date?->format('Y-m-d H:i:s').'|'.$last->id : null];
    }

    public function show(Request $r, int $id)
    {
        $m = $this->message($r, $id);
        $warn = null;
        if (! $m->body_loaded || ($m->account->provider === 'microsoft' && ($m->has_attachments || str_contains((string) $m->body_html, 'cid:')) && empty($m->attachments))) {
            try {
                $this->svc->loadBody($m);
                $m->refresh();
            } catch (MailProtocolException $e) {
                $warn = $e->getMessage();
            }
        }
        if ($r->boolean('read') && ! $m->seen) {
            try {
                $this->svc->flags($m, ['seen' => true]);
            } catch (MailProtocolException) { // hors ligne côté fournisseur : état local, reporté à la prochaine synchro
            }
        }
        $thread = $m->thread_key ? Message::whereIn('account_id', $this->accountIds($r))->where('thread_key', $m->thread_key)->where('id', '!=', $m->id)
            ->orderBy('date')->limit(30)->get()->map(fn ($x) => self::messageRow($x))->values() : [];
        $partner = $m->partner_id ? DB::table('partners')->where('id', $m->partner_id)->first(['id', 'name', 'type', 'phone', 'email']) : null;

        $fresh = $m->fresh();

        return ['data' => self::messageRow($fresh, true), 'thread' => $thread, 'partner' => $partner, 'warning' => $warn,
            'insights' => rescue(fn () => MailInsights::analyze($fresh), null, false), 'actions' => $this->actionsFor($r)];
    }

    /** Ce que l'utilisateur peut créer à partir d'un e-mail, selon ses modules et ses droits. */
    protected function actionsFor(Request $r): array
    {
        $c = app('tenant');
        $u = $r->user();
        $mods = $u->allowedModules($c->enabledModules());
        $has = fn (string $m, string $perm) => in_array($m, $mods, true) && $u->hasPermission($perm);

        return array_keys(array_filter([
            'task' => true,
            'meeting' => in_array('agenda', $mods, true) && $u->hasPermission('agenda.use|agenda.manage'),
            'visio' => $has('visio', 'visio.use|visio.manage'),
            'webinar' => $has('visio', 'visio.webinar|visio.manage'),
            'quote' => $has('ventes', 'ventes.manage'),
            'ticket' => $has('helpdesk', 'helpdesk.agent|helpdesk.manage'),
            'lead' => $has('crm', 'crm.manage'),
            'funds' => $has('fonds', 'fonds.request|fonds.manage'),
        ]));
    }

    public function flags(Request $r, int $id)
    {
        $d = $r->validate(['seen' => 'nullable|boolean', 'flagged' => 'nullable|boolean']);
        $m = $this->message($r, $id);
        try {
            $this->svc->flags($m, array_filter($d, fn ($v) => $v !== null));
        } catch (MailProtocolException $e) {
            return $this->fail($e);
        }

        return ['data' => self::messageRow($m->fresh())];
    }

    public function move(Request $r, int $id)
    {
        $d = $r->validate(['folder_id' => 'required|integer']);
        $m = $this->message($r, $id);
        $f = Folder::where('account_id', $m->account_id)->findOrFail($d['folder_id']);
        try {
            $this->svc->move($m, $f);
        } catch (MailProtocolException $e) {
            return $this->fail($e);
        }

        return response()->noContent();
    }

    public function destroy(Request $r, int $id)
    {
        $m = $this->message($r, $id);
        try {
            $this->svc->delete($m);
        } catch (MailProtocolException $e) {
            return $this->fail($e);
        }

        return response()->noContent();
    }

    public function bulk(Request $r)
    {
        $d = $r->validate(['ids' => 'required|array|max:200', 'ids.*' => 'integer', 'action' => 'required|in:read,unread,flag,unflag,delete,move,archive', 'folder_id' => 'nullable|integer']);
        $done = 0;
        $errors = [];
        foreach (Message::whereIn('id', $d['ids'])->whereIn('account_id', $this->accountIds($r) ?: [0])->get() as $m) {
            try {
                match ($d['action']) {
                    'read' => $this->svc->flags($m, ['seen' => true]),
                    'unread' => $this->svc->flags($m, ['seen' => false]),
                    'flag' => $this->svc->flags($m, ['flagged' => true]),
                    'unflag' => $this->svc->flags($m, ['flagged' => false]),
                    'delete' => $this->svc->delete($m),
                    'move' => $this->svc->move($m, Folder::where('account_id', $m->account_id)->findOrFail($d['folder_id'])),
                    'archive' => $this->svc->move($m, Folder::where('account_id', $m->account_id)->where('role', 'archive')->firstOr(fn () => throw new \DomainException('Ce compte n\'a pas de dossier Archives.'))),
                };
                $done++;
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        $this->svc->close();

        return ['done' => $done, 'errors' => array_values(array_unique($errors))];
    }

    public function download(Request $r, int $id, string $att)
    {
        $m = $this->message($r, $id);
        $dec = base64_decode(strtr($att, '-_', '+/'), true);

        return $this->stream($m, $dec !== false && $dec !== '' && $r->boolean('b64', true) ? $dec : $att, $r->boolean('inline'));
    }

    /** Lien signé (images intégrées dans l'affichage, téléchargement depuis le navigateur). */
    public function signedAttachment(Request $r, int $id, string $att)
    {
        $m = Message::withoutGlobalScopes()->findOrFail($id);
        app()->instance('tenant', \App\Models\Company::find($m->company_id));

        return $this->stream($m, base64_decode(strtr($att, '-_', '+/')), $r->boolean('inline'));
    }

    protected function stream(Message $m, string $att, bool $inline)
    {
        try {
            $x = $this->svc->attachment($m, $att);
        } catch (MailProtocolException $e) {
            abort(404, $e->getMessage());
        }
        abort_unless($x, 404, 'Pièce jointe introuvable.');
        $mime = $x['mime'] ?: 'application/octet-stream';
        $safeInline = $inline && preg_match('#^image/(png|jpe?g|gif|webp|bmp)$#', $mime);

        return response($x['content'], 200, ['Content-Type' => $safeInline || ! $inline ? $mime : 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Disposition' => ($safeInline ? 'inline' : 'attachment').'; filename="'.str_replace(['"', "\n", "\r"], '', $x['name']).'"; filename*=UTF-8\'\''.rawurlencode($x['name'])]);
    }

    /** Transforme un mail en tâche (Ma journée), ticket du service client ou opportunité CRM. */
    public function convert(Request $r, int $id)
    {
        $d = $r->validate(['type' => 'required|in:task,ticket,lead,quote,funds,meeting,visio,webinar', 'start' => 'nullable|date', 'duration' => 'nullable|integer|between:5,720',
            'provider' => 'nullable|in:teams,meet,onsite', 'title' => 'nullable|string|max:190', 'guests' => 'nullable|array|max:100', 'guests.*.email' => 'nullable|email', 'guests.*.name' => 'nullable|string|max:120',
            'location' => 'nullable|string|max:190', 'amount' => 'nullable|numeric|min:0', 'send_invites' => 'nullable|boolean']);
        $m = $this->message($r, $id);
        $c = app('tenant');
        $u = $r->user();
        abort_unless(in_array($d['type'], $this->actionsFor($r), true), 403, 'Ce module ou ce droit ne vous est pas attribué.');
        $title = mb_substr($d['title'] ?? ($m->subject ?: '(sans objet)'), 0, 190);
        $excerpt = trim(mb_substr(MailInsights::plain($m), 0, 4000));
        $from = trim(($m->from_name ? $m->from_name.' ' : '').'<'.$m->from_email.'>');
        $intro = 'E-mail de '.$from.' du '.($m->date?->format('d/m/Y H:i') ?? '').' : « '.($m->subject ?: '(sans objet)').' »';
        $ok = fn (string $type, $id, string $msg, array $extra = []) => response()->json(['type' => $type, 'id' => $id, 'message' => $msg] + $extra, 201);
        $writer = app(EntityWriter::class);
        $check = function (array $res) {
            if (($res['status'] ?? '') !== EntityWriter::APPLIED) {
                throw new \DomainException($res['message'] ?? 'Création impossible.');
            }

            return $res['record']['id'] ?? null;
        };
        $partner = function () use ($m) {
            if ($m->partner_id) {
                return $m->partner_id;
            }
            $p = $m->from_email ? \App\Modules\Core\Models\Partner::whereRaw('lower(email) = ?', [mb_strtolower($m->from_email)])->first() : null;
            $p ??= \App\Modules\Core\Models\Partner::create(['name' => mb_substr($m->from_name ?: $m->from_email, 0, 160), 'type' => 'prospect', 'email' => $m->from_email,
                'notes' => 'Contact créé depuis un e-mail']);
            $m->forceFill(['partner_id' => $p->id])->saveQuietly();

            return $p->id;
        };
        try {
            switch ($d['type']) {
                case 'task':
                    $tid = DB::table('user_todos')->insertGetId(['company_id' => $c->id, 'user_id' => $u->id, 'title' => 'Mail : '.$title, 'due_date' => ! empty($d['start']) ? Carbon::parse($d['start'])->toDateString() : null,
                        'important' => $m->flagged, 'my_day' => true, 'created_at' => now(), 'updated_at' => now()]);

                    return $ok('task', $tid, 'Tâche ajoutée à Ma journée.');
                case 'ticket':
                    $id = $check($writer->upsert(\App\Modules\Helpdesk\Models\Ticket::class, (string) Str::uuid(), ['subject' => $title, 'description' => mb_substr((string) $m->body_text, 0, 10000),
                        'channel' => 'email', 'contact_name' => $m->from_name, 'contact_email' => $m->from_email, 'partner_id' => $m->partner_id]));

                    return $ok('ticket', $id, 'Ticket créé dans le Service client.');
                case 'lead':
                    $id = $check($writer->upsert(\App\Modules\Crm\Models\Lead::class, (string) Str::uuid(), ['title' => $title, 'contact_name' => $m->from_name, 'email' => $m->from_email,
                        'partner_id' => $m->partner_id, 'source' => 'other', 'notes' => mb_substr((string) $m->body_text, 0, 5000), 'owner_id' => $u->id] + (isset($d['amount']) ? ['expected_revenue' => $d['amount']] : [])));

                    return $ok('lead', $id, 'Opportunité créée dans le CRM.');
                case 'quote':
                    $id = (string) Str::uuid();
                    $check($writer->upsert(\App\Modules\Ventes\Models\SalesDocument::class, $id, ['type' => 'quote', 'partner_id' => $partner(), 'date' => now()->toDateString(), 'status' => 'draft',
                        'reference' => mb_substr('Demande du '.($m->date?->format('d/m/Y') ?? now()->format('d/m/Y')), 0, 60), 'notes' => mb_substr($intro."\n\n".$excerpt, 0, 5000),
                        'lines' => [['id' => (string) Str::uuid(), 'description' => $title, 'quantity' => 1, 'unit_price' => (float) ($d['amount'] ?? 0), 'tax_rate' => 0, 'discount_percent' => 0]]]));

                    return $ok('quote', $id, 'Devis en brouillon créé : complétez les articles et les prix.');
                case 'funds':
                    $amount = (float) ($d['amount'] ?? MailInsights::amount(MailInsights::plain($m)) ?? 0);
                    $req = app(\App\Modules\Fonds\FundService::class)->save($c, $u, null, ['title' => $title, 'purpose' => mb_substr($intro."\n\n".$excerpt, 0, 5000), 'priority' => 'normal',
                        'lines' => [['type' => 'funds', 'description' => $title, 'quantity' => 1, 'unit_amount' => $amount]]]);

                    return $ok('funds', $req->id, 'Appel de fonds créé en brouillon.');
                case 'meeting':
                    return $ok('meeting', ...$this->meetingFrom($r, $m, $d, $title, $intro, $excerpt));
                case 'visio':
                case 'webinar':
                    $guests = $this->guestsFor($r, $m, $d);
                    $req = Request::create('/api/visio/meetings', 'POST', ['type' => $d['type'] === 'webinar' ? 'webinar' : 'meeting', 'title' => $title, 'description' => $intro,
                        'starts_at' => $d['start'] ?? now()->addDay()->setTime(10, 0)->toIso8601String(), 'duration_minutes' => $d['duration'] ?? ($d['type'] === 'webinar' ? 90 : 60),
                        'guests' => $d['type'] === 'webinar' ? [] : $guests, 'send_invites' => $d['send_invites'] ?? true]);
                    $req->setUserResolver(fn () => $u);
                    $res = app(\App\Modules\Visio\VisioController::class)->store($req);
                    $j = $res->getData(true);

                    return $ok($d['type'], $j['data']['id'] ?? null, $d['type'] === 'webinar' ? 'Webinaire programmé : partagez sa page d\'inscription.' : 'Réunion vidéo programmée, invitations envoyées ('.($j['sent'] ?? 0).').',
                        ['join_url' => $j['join_url'] ?? null]);
            }
        } catch (\DomainException $e) {
            return $this->fail($e);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first() ?? 'Données invalides.'], 422);
        }

        return $this->fail(new \DomainException('Action inconnue.'));
    }

    /** Invités : l'expéditeur et les autres destinataires (sauf mes propres adresses), ou la liste fournie. */
    protected function guestsFor(Request $r, Message $m, array $d): array
    {
        if (! empty($d['guests'])) {
            return array_values(array_filter($d['guests'], fn ($g) => ! empty($g['email'])));
        }
        $mine = Account::whereIn('id', $this->accountIds($r))->pluck('email')->map(fn ($e) => mb_strtolower($e))->all();
        $out = [];
        foreach (array_merge([['email' => $m->from_email, 'name' => $m->from_name]], (array) $m->to, (array) $m->cc) as $p) {
            $e = mb_strtolower((string) ($p['email'] ?? ''));
            if ($e && ! in_array($e, $mine, true) && ! isset($out[$e])) {
                $out[$e] = ['email' => $e, 'name' => $p['name'] ?? null];
            }
        }

        return array_values($out);
    }

    /** Réunion dans l'Agenda (Teams / Meet créés par Outlook ou Google, ou en présentiel) avec les invités. */
    protected function meetingFrom(Request $r, Message $m, array $d, string $title, string $intro, string $excerpt): array
    {
        $u = $r->user();
        $c = app('tenant');
        $tz = config('erp.defaults.timezone', 'UTC');
        $start = Carbon::parse($d['start'] ?? now($tz)->addDay()->setTime(10, 0))->utc();
        $end = $start->copy()->addMinutes($d['duration'] ?? 60);
        $provider = $d['provider'] ?? 'onsite';
        $guests = $this->guestsFor($r, $m, $d);
        $e = new \App\Modules\Agenda\Models\Event;
        $e->forceFill(['id' => (string) Str::uuid(), 'company_id' => $c->id, 'owner_id' => $u->id, 'title' => $title, 'description' => mb_substr($intro."\n\n".$excerpt, 0, 4000),
            'location' => $d['location'] ?? null, 'online_link' => in_array($provider, ['teams', 'meet'], true) ? $provider : null, 'starts_at' => $start, 'ends_at' => $end, 'all_day' => false,
            'category' => 'meeting', 'visibility' => 'public', 'attendee_ids' => [$u->id], 'guests' => $guests ?: null, 'status' => 'confirmed', 'reminder_minutes' => 15])->save();
        $sent = 0;
        $cal = null;
        if (in_array($provider, ['teams', 'meet'], true)) {
            // Le calendrier Outlook / Google crée le lien Teams ou Meet et envoie les invitations
            $cal = Account::where('user_id', $u->id)->where('provider', $provider === 'teams' ? 'microsoft' : 'google')->first();
            if ($cal) {
                rescue(fn () => app(CalendarSync::class)->sync($cal), null, false);
            }
        }
        if (! $cal && ($d['send_invites'] ?? true) && $guests) {
            $sent = app(\App\Modules\Agenda\AgendaService::class)->inviteGuests($e->fresh());
        }
        $e->refresh();
        $msg = 'Réunion ajoutée à l\'agenda le '.Carbon::parse($e->starts_at)->tz($tz)->format('d/m/Y à H:i');
        $msg .= $cal ? ($provider === 'teams' ? ' : réunion Teams créée dans Outlook, invitations envoyées par Outlook.' : ' : Google Meet créé, invitations envoyées par Google.')
            : ($provider !== 'onsite' ? ' (branchez votre compte '.($provider === 'teams' ? 'Microsoft 365' : 'Google').' dans la Boîte mail pour créer le lien '.($provider === 'teams' ? 'Teams' : 'Meet').')' : '').($sent ? ', '.$sent.' invitation(s) envoyée(s).' : '.');

        return [$e->id, $msg, ['online_link' => $e->online_link && str_starts_with((string) $e->online_link, 'http') ? $e->online_link : null]];
    }

    /** Invitation reçue (.ics) : ajoutée à mon agenda. */
    public function acceptInvite(Request $r, int $id)
    {
        $m = $this->message($r, $id);
        $inv = MailInsights::invite($m);
        abort_unless($inv, 422, 'Pas d\'invitation dans ce message.');
        $c = app('tenant');
        abort_unless($c->hasModule('agenda'), 422, 'Le module Agenda n\'est pas actif.');
        $u = $r->user();
        $exists = $inv['uid'] ? DB::table('ag_events')->where('company_id', $c->id)->where('owner_id', $u->id)->whereNull('deleted_at')->where('description', 'like', '%Réf. invitation : '.$inv['uid'].'%')->value('id') : null;
        if ($exists) {
            return response()->json(['id' => $exists, 'message' => 'Déjà dans votre agenda.']);
        }
        $e = new \App\Modules\Agenda\Models\Event;
        $e->forceFill(['id' => (string) Str::uuid(), 'company_id' => $c->id, 'owner_id' => $u->id, 'title' => mb_substr($inv['summary'], 0, 190),
            'description' => trim('Invitation de '.($inv['organizer']['name'] ?? $inv['organizer']['email'] ?? $m->from_email).($inv['uid'] ? "\nRéf. invitation : ".$inv['uid'] : '')),
            'location' => $inv['location'] ? mb_substr($inv['location'], 0, 190) : null, 'online_link' => $inv['link'], 'starts_at' => Carbon::parse($inv['start'])->utc(), 'ends_at' => Carbon::parse($inv['end'])->utc(),
            'all_day' => $inv['all_day'], 'category' => 'meeting', 'visibility' => 'public', 'attendee_ids' => [$u->id], 'status' => 'confirmed', 'reminder_minutes' => 15,
            'guests' => $inv['organizer']['email'] ? [['email' => $inv['organizer']['email'], 'name' => $inv['organizer']['name']]] : null])->save();

        return response()->json(['id' => $e->id, 'message' => 'Ajouté à votre agenda.'], 201);
    }

    // ------------------------------------------------------------------ envoi

    public function send(Request $r)
    {
        $d = $r->validate([
            'id' => 'required|uuid', 'account_id' => 'required|integer', 'to' => 'nullable|array|max:100', 'cc' => 'nullable|array|max:100', 'bcc' => 'nullable|array|max:200',
            'subject' => 'nullable|string|max:300', 'html' => 'nullable|string|max:3000000', 'mode' => 'nullable|in:new,reply,reply_all,forward', 'message_id' => 'nullable|integer',
            'attachments' => 'nullable|array|max:30', 'send_at' => 'nullable|date', 'read_receipt' => 'nullable|boolean', 'importance' => 'nullable|in:low,normal,high',
        ]);
        $a = $this->account($r, (int) $d['account_id']);
        if (! empty($d['message_id'])) {
            $this->message($r, (int) $d['message_id']);
        }
        if ($prev = DB::table('mb_outbox')->where('id', $d['id'])->first()) { // requête rejouée par le poste : déjà traitée
            // l'envoi d'un autre utilisateur (ou d'une autre société) n'est jamais renvoyé
            abort_unless((int) $prev->company_id === (int) app('tenant')->id && (int) $prev->user_id === (int) $r->user()->id, 422, 'Identifiant d\'envoi déjà utilisé.');

            return response()->json(['data' => $this->outboxRow($prev)], 201);
        }
        try {
            $mail = Composer::build($a, $d, $r->user()); // validation immédiate (destinataires, pièces jointes)
            Antispam::check($a, $mail);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
        $row = $this->svc->queue($a, $r->user()->id, $d['id'], $d, $mail);

        return response()->json(['data' => $this->outboxRow((object) $row)], $row['status'] === 'failed' ? 422 : 201);
    }

    protected function outboxRow(object $o): array
    {
        $p = json_decode((string) $o->payload, true) ?: [];

        return ['id' => $o->id, 'account_id' => $o->account_id, 'status' => $o->status, 'error' => $o->error, 'send_at' => $o->send_at, 'sent_at' => $o->sent_at, 'attempts' => (int) $o->attempts,
            'subject' => $p['subject'] ?? '', 'to' => $p['to'] ?? [], 'created_at' => $o->created_at];
    }

    protected function outboxRows(Request $r): array
    {
        return DB::table('mb_outbox')->where('company_id', app('tenant')->id)->where('user_id', $r->user()->id)
            ->where(fn ($q) => $q->whereIn('status', ['queued', 'sending', 'failed'])->orWhere('sent_at', '>=', now()->subDay()))
            ->orderByDesc('created_at')->limit(50)->get()->map(fn ($o) => $this->outboxRow($o))->all();
    }

    public function outbox(Request $r)
    {
        return ['data' => $this->outboxRows($r)];
    }

    public function cancelOutbox(Request $r, string $id)
    {
        $n = DB::table('mb_outbox')->where('id', $id)->where('user_id', $r->user()->id)->whereIn('status', ['queued', 'failed'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        abort_unless($n, 422, 'Ce message est déjà parti.');

        return response()->noContent();
    }

    public function retryOutbox(Request $r, string $id)
    {
        $n = DB::table('mb_outbox')->where('id', $id)->where('user_id', $r->user()->id)->where('status', 'failed')->update(['status' => 'queued', 'attempts' => 0, 'send_at' => null, 'updated_at' => now()]);
        abort_unless($n, 422, 'Rien à renvoyer.');
        $this->svc->deliver($id);

        return ['data' => $this->outboxRow(DB::table('mb_outbox')->find($id))];
    }

    // ------------------------------------------------------------------ comptes

    /** Réglages devinés à partir de l'adresse : fournisseurs connus, domaine hébergé chez Microsoft / Google (MX), cPanel. */
    public function detect(Request $r)
    {
        $d = $r->validate(['email' => 'required|email']);
        $domain = mb_strtolower(substr(strrchr($d['email'], '@'), 1));
        $known = [
            'gmail.com' => 'google', 'googlemail.com' => 'google', 'outlook.com' => 'microsoft', 'hotmail.com' => 'microsoft', 'hotmail.fr' => 'microsoft', 'live.com' => 'microsoft',
            'live.fr' => 'microsoft', 'msn.com' => 'microsoft', 'outlook.fr' => 'microsoft',
        ];
        $presets = [
            'yahoo.com' => ['imap.mail.yahoo.com', 'smtp.mail.yahoo.com'], 'yahoo.fr' => ['imap.mail.yahoo.com', 'smtp.mail.yahoo.com'], 'icloud.com' => ['imap.mail.me.com', 'smtp.mail.me.com', 587, 'starttls'],
            'gmail.com' => ['imap.gmail.com', 'smtp.gmail.com'], 'outlook.com' => ['outlook.office365.com', 'smtp.office365.com', 587, 'starttls'], 'hotmail.com' => ['outlook.office365.com', 'smtp.office365.com', 587, 'starttls'],
            'orange.fr' => ['imap.orange.fr', 'smtp.orange.fr'], 'zoho.com' => ['imap.zoho.com', 'smtp.zoho.com'], 'gmx.fr' => ['imap.gmx.net', 'mail.gmx.net', 587, 'starttls'],
        ];
        if (isset($known[$domain])) {
            if (OAuth::enabled($known[$domain])) {
                return ['provider' => $known[$domain], 'oauth' => true];
            }
        }
        // messageries grand public connues : réglages IMAP directs (avant la recherche DNS, qui renverrait Google ou Microsoft)
        if (isset($presets[$domain])) {
            $p = $presets[$domain];

            return ['provider' => 'imap', 'settings' => ['imap_host' => $p[0], 'imap_port' => 993, 'imap_security' => 'ssl', 'smtp_host' => $p[1], 'smtp_port' => $p[2] ?? 465, 'smtp_security' => $p[3] ?? 'ssl']];
        }
        $mx = [];
        if (function_exists('dns_get_record')) {
            $mx = array_map(fn ($x) => mb_strtolower($x['target'] ?? ''), (array) (@dns_get_record($domain, DNS_MX) ?: []));
        }
        foreach ($mx as $h) {
            if (str_ends_with($h, 'mail.protection.outlook.com')) {
                return ['provider' => 'microsoft', 'oauth' => OAuth::enabled('microsoft'), 'hint' => 'Ce domaine est hébergé sur Microsoft 365.'];
            }
            if (str_ends_with($h, 'google.com') || str_ends_with($h, 'googlemail.com')) {
                return ['provider' => 'google', 'oauth' => OAuth::enabled('google'), 'hint' => 'Ce domaine est hébergé sur Google Workspace.'];
            }
        }

        // hébergement mutualisé (cPanel, Plesk…) : mail.domaine sur les ports sécurisés
        return ['provider' => 'imap', 'settings' => ['imap_host' => 'mail.'.$domain, 'imap_port' => 993, 'imap_security' => 'ssl', 'smtp_host' => 'mail.'.$domain, 'smtp_port' => 465, 'smtp_security' => 'ssl',
            'pop3_host' => 'mail.'.$domain, 'pop3_port' => 995, 'pop3_security' => 'ssl'], 'hint' => 'Réglages habituels d\'un hébergement cPanel : vérifiez-les auprès de votre hébergeur si la connexion échoue.'];
    }

    protected function accountRules(bool $create): array
    {
        $req = $create ? 'required' : 'nullable';

        return [
            'provider' => ($create ? 'required' : 'prohibited').'|in:imap,pop3', 'email' => ($create ? 'required' : 'nullable').'|email|max:190', 'display_name' => 'nullable|string|max:120',
            'password' => 'nullable|string|max:300', 'smtp_password' => 'nullable|string|max:300', 'color' => 'nullable|string|max:9', 'sync_days' => 'nullable|integer|min:1|max:365',
            'calendar_sync' => 'nullable|boolean', 'shared' => 'nullable|boolean', 'shared_with' => 'nullable|array', 'shared_with.*' => 'integer', 'settings' => 'nullable|array',
            'settings.imap_host' => 'nullable|string|max:190', 'settings.imap_port' => 'nullable|integer|min:1|max:65535', 'settings.imap_security' => 'nullable|in:ssl,starttls,none',
            'settings.smtp_host' => 'nullable|string|max:190', 'settings.smtp_port' => 'nullable|integer|min:1|max:65535', 'settings.smtp_security' => 'nullable|in:ssl,starttls,none',
            'settings.pop3_host' => 'nullable|string|max:190', 'settings.pop3_port' => 'nullable|integer|min:1|max:65535', 'settings.pop3_security' => 'nullable|in:ssl,starttls,none',
            'settings.username' => 'nullable|string|max:190', 'settings.smtp_username' => 'nullable|string|max:190', 'settings.pop_keep' => 'nullable|boolean',
        ];
    }

    public function storeAccount(Request $r)
    {
        $d = $r->validate($this->accountRules(true) + ['password' => 'required|string|max:300']);
        $shared = (bool) ($d['shared'] ?? false);
        abort_if($shared && ! $this->canManage($r), 403, 'Seul un gestionnaire de la boîte mail peut créer une boîte partagée.');
        $email = mb_strtolower($d['email']);
        abort_if(Account::where('email', $email)->where('user_id', $shared ? null : $r->user()->id)->exists(), 422, 'Ce compte est déjà ajouté.');
        $a = new Account(['provider' => $d['provider'], 'email' => $email, 'display_name' => $d['display_name'] ?? null, 'color' => $d['color'] ?? '#2E6BE6',
            'sync_days' => $d['sync_days'] ?? 30, 'settings' => $d['settings'] ?? [], 'user_id' => $shared ? null : $r->user()->id, 'shared_with' => $shared ? array_values(array_unique(array_map('intval', $d['shared_with'] ?? []))) : null]);
        $a->setSecret(array_filter(['password' => $d['password'], 'smtp_password' => $d['smtp_password'] ?? null]));
        $a->company_id = app('tenant')->id;
        try {
            $this->svc->driver($a)->test($a);
        } catch (MailProtocolException|\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            return response()->json(['message' => 'Connexion impossible : '.$e->getMessage()], 422);
        } finally {
            $this->svc->close();
        }
        $a->next_sync_at = now();
        $a->save();
        try {
            $this->svc->sync($a, true);
        } catch (\Throwable) {
        }

        return response()->json(['data' => self::accountRow($a->fresh())], 201);
    }

    public function updateAccount(Request $r, int $id)
    {
        $a = $this->account($r, $id, true);
        $d = $r->validate($this->accountRules(false));
        foreach (['display_name', 'color', 'sync_days', 'calendar_sync'] as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null) {
                $a->{$k} = $d[$k];
            }
        }
        if (isset($d['settings']) && ! $a->isOAuth()) {
            $a->settings = array_merge($a->settings ?? [], $d['settings']);
        }
        if ($a->user_id === null && array_key_exists('shared_with', $d) && $this->canManage($r)) {
            $a->shared_with = array_values(array_unique(array_map('intval', $d['shared_with'] ?? [])));
        }
        if (! $a->isOAuth() && (! empty($d['password']) || ! empty($d['smtp_password']))) {
            $a->setSecret(array_filter(array_merge($a->secret(), array_filter(['password' => $d['password'] ?? null, 'smtp_password' => $d['smtp_password'] ?? null]))));
        }
        if ($a->isDirty(['settings', 'credentials'])) {
            try {
                $this->svc->driver($a)->test($a);
            } catch (MailProtocolException|\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
                return response()->json(['message' => 'Connexion impossible : '.$e->getMessage()], 422);
            } finally {
                $this->svc->close();
            }
            $a->status = 'active';
            $a->error = null;
            $a->next_sync_at = now();
        }
        $a->save();

        return ['data' => self::accountRow($a)];
    }

    public function destroyAccount(Request $r, int $id)
    {
        $a = $this->account($r, $id, true);
        if ($a->setting('hosted') === true && HostedMail::available()) {
            try {
                HostedMail::remove($a); // la boîte et ses messages sont supprimés du serveur Yomara
            } catch (\DomainException $e) {
                if (! str_contains(strtolower($e->getMessage()), 'exist')) {
                    return $this->fail($e);
                }
            }
        }
        Message::withTrashed()->where('account_id', $a->id)->forceDelete();
        Folder::where('account_id', $a->id)->delete();
        DB::table('ag_event_links')->where('account_id', $a->id)->delete();
        DB::table('mb_outbox')->where('account_id', $a->id)->whereIn('status', ['queued', 'failed'])->update(['status' => 'cancelled', 'updated_at' => now()]);
        Account::where('settings->token_account_id', $a->id)->get()->each(fn ($s) => $s->forceFill(['status' => 'error', 'error' => 'Le compte Microsoft d\'accès a été supprimé.'])->save());
        $a->delete();

        return response()->noContent();
    }

    /** Image pour une signature ou un message HTML : adresse publique renvoyée. */
    public function uploadMedia(Request $r)
    {
        $r->validate(['file' => 'required|file|max:3072']);
        try {
            return response()->json(['url' => Media::store($r->file('file'))], 201);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
    }

    /** Photo de profil de l'adresse (affichée dans Yomara ; publiée aussi sur Microsoft 365 si demandé). */
    public function avatar(Request $r, int $id)
    {
        $a = $this->account($r, $id, true);
        $r->validate(['file' => 'required|file|max:4096', 'publish' => 'nullable']);
        try {
            $url = Media::store($r->file('file'));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
        Media::delete($a->avatar_url);
        $a->avatar_url = $url;
        $a->save();
        $published = false;
        $warning = null;
        if ($a->provider === 'microsoft' && filter_var($r->input('publish'), FILTER_VALIDATE_BOOLEAN) && ! $a->setting('graph_user')) {
            try {
                $res = \Illuminate\Support\Facades\Http::withToken(OAuth::token($a))->withBody($r->file('file')->get(), $r->file('file')->getMimeType() ?: 'image/jpeg')
                    ->timeout(30)->put('https://graph.microsoft.com/v1.0/me/photo/$value');
                $published = $res->successful();
                $warning = $published ? null : 'Microsoft 365 a refusé la photo : '.($res->json('error.message') ?: 'erreur '.$res->status());
            } catch (\Throwable $e) {
                $warning = $e->getMessage();
            }
        }

        return ['data' => self::accountRow($a), 'published' => $published, 'warning' => $warning];
    }

    public function removeAvatar(Request $r, int $id)
    {
        $a = $this->account($r, $id, true);
        Media::delete($a->avatar_url);
        $a->avatar_url = null;
        $a->save();

        return ['data' => self::accountRow($a)];
    }

    /** Fichier public d'une image de signature / photo de profil. */
    public function media(string $name)
    {
        $p = Media::path($name);
        abort_unless($p, 404);

        return response()->file($p, ['Cache-Control' => 'public, max-age=31536000, immutable', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Page d'installation de l'application mobile (cible du QR code). */
    /** QR code « Application mobile » : lien intelligent selon l'appareil (voir AppSmartLink). */
    public function mobilePage(\Illuminate\Http\Request $r)
    {
        return \App\Support\AppSmartLink::handle($r);
    }

    /** Adresse fournie par Yomara (boîte créée sur le cPanel de la plateforme). */
    public function storeHosted(Request $r)
    {
        $d = $r->validate(['local' => 'required|string|max:40', 'display_name' => 'nullable|string|max:120', 'shared' => 'nullable|boolean', 'shared_with' => 'nullable|array', 'shared_with.*' => 'integer']);
        $shared = (bool) ($d['shared'] ?? false);
        abort_if($shared && ! $this->canManage($r), 403, 'Seul un gestionnaire de la boîte mail peut créer une boîte partagée.');
        try {
            $a = HostedMail::create(app('tenant'), $r->user()->id, $d['local'], $d['display_name'] ?? null, $shared, $d['shared_with'] ?? []);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
        try {
            $this->svc->sync($a, true);
        } catch (\Throwable) {
        }

        return response()->json(['data' => self::accountRow($a->fresh())], 201);
    }

    public function hostedPassword(Request $r, int $id)
    {
        $a = $this->account($r, $id, true);
        abort_unless($a->setting('hosted') === true, 422, 'Réservé aux adresses fournies par Yomara.');
        try {
            $p = HostedMail::resetPassword($a);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return ['password' => $p, 'email' => $a->email, 'imap' => $a->setting('imap_host'), 'smtp' => $a->setting('smtp_host'), 'usage' => HostedMail::usage($a)];
    }

    /** Délivrabilité du domaine d'envoi : SPF, DKIM, DMARC, MX. */
    public function deliverability(Request $r, int $id)
    {
        $a = $this->account($r, $id);

        return ['data' => Antispam::deliverability(substr(strrchr($a->email, '@'), 1), $a->setting('smtp_host')) + ['limits' => Antispam::limits($a)]];
    }

    /**
     * Boîte ouverte à l'écran (appel toutes les ~20 s) : les comptes de l'utilisateur passent en synchro rapprochée
     * et ceux dont la dernière relève date de plus de 30 s sont relevés tout de suite, pour un affichage quasi instantané.
     */
    public function live(Request $r)
    {
        $uid = $r->user()->id;
        $synced = 0;
        $new = 0;
        foreach (Account::forUser($uid)->where('status', '!=', 'paused')->get() as $a) {
            \Illuminate\Support\Facades\Cache::put('mailbox-live-'.$a->id, true, now()->addMinutes(2));
            if ($a->last_sync_at && $a->last_sync_at->gt(now()->subSeconds(30))) {
                continue;
            }
            try {
                $res = $this->svc->sync($a);
                $synced++;
                $new += (int) ($res['new'] ?? $res['fetched'] ?? 0);
            } catch (\Throwable) {
                // erreur enregistrée sur le compte ; l'interface l'affiche
            }
        }

        return ['synced' => $synced, 'new' => $new, 'server_time' => now()->toIso8601String()];
    }

    /** Assistant de messagerie : résumé, actions, réponse rédigée, traduction (inclus avec le module). */
    public function assist(Request $r, int $id)
    {
        $m = $this->message($r, $id);
        $d = $r->validate(['action' => 'required|in:'.implode(',', MailAssist::ACTIONS), 'tone' => 'nullable|string|max:40', 'instructions' => 'nullable|string|max:500',
            'lang' => 'nullable|in:'.implode(',', array_keys(MailAssist::LANGS))]);
        if (! $m->body_loaded) {
            rescue(fn () => $this->svc->loadBody($m), null, false);
            $m->refresh();
        }

        return ['data' => app(MailAssist::class)->run(app('tenant'), $r->user(), $m, $d['action'], $d)];
    }

    public function syncAccount(Request $r, int $id)
    {
        $a = $this->account($r, $id);
        try {
            $res = $this->svc->sync($a, true);
        } catch (MailProtocolException|\DomainException $e) {
            return $this->fail($e);
        }

        return ['data' => $res, 'account' => self::accountRow($a->fresh())];
    }

    public function oauthStart(Request $r, string $provider)
    {
        abort_unless(in_array($provider, ['microsoft', 'google'], true), 404);
        abort_unless(OAuth::enabled($provider), 403, 'La connexion '.($provider === 'google' ? 'Gmail' : 'Outlook').' est désactivée sur cette plateforme. Ajoutez la boîte en IMAP.');
        $d = $r->validate(['calendar' => 'nullable|boolean', 'email' => 'nullable|email', 'sync_days' => 'nullable|integer|min:1|max:365']);
        try {
            return ['url' => OAuth::authorizeUrl(app('tenant'), $r->user()->id, $provider, $d + ['calendar' => $d['calendar'] ?? true])];
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
    }

    /** Boîte partagée Microsoft 365 (contact@, compta@…) ouverte avec le compte Microsoft de l'utilisateur. */
    public function addShared(Request $r, int $id)
    {
        $owner = $this->account($r, $id, true);
        abort_unless($owner->provider === 'microsoft', 422, 'Réservé aux comptes Microsoft 365.');
        $d = $r->validate(['email' => 'required|email', 'display_name' => 'nullable|string|max:120']);
        $email = mb_strtolower($d['email']);
        $a = new Account(['provider' => 'microsoft', 'email' => $email, 'display_name' => $d['display_name'] ?? $email, 'color' => '#5B5FC7', 'user_id' => $r->user()->id,
            'sync_days' => $owner->sync_days, 'settings' => ['graph_user' => $email, 'token_account_id' => $owner->id]]);
        $a->company_id = app('tenant')->id;
        try {
            (new GraphDriver)->test($a);
        } catch (MailProtocolException $e) {
            return response()->json(['message' => 'Accès refusé à cette boîte : vérifiez que l\'administrateur Microsoft 365 vous a donné l\'accès (autorisation « Accès total »). '.$e->getMessage()], 422);
        }
        $a->next_sync_at = now();
        $a->save();

        return response()->json(['data' => self::accountRow($a)], 201);
    }

    /** Contacts Outlook ou Google → fiches clients (les adresses déjà connues sont ignorées). */
    public function importContacts(Request $r, int $id)
    {
        $a = $this->account($r, $id);
        abort_unless(in_array($a->provider, ['microsoft', 'google'], true), 422, 'Import possible depuis Microsoft 365 ou Google.');
        $contacts = [];
        try {
            $token = OAuth::token($a);
            if ($a->provider === 'microsoft') {
                $url = 'https://graph.microsoft.com/v1.0/me/contacts?$top=200&$select=displayName,emailAddresses,mobilePhone,businessPhones,companyName';
                for ($i = 0; $url && $i < 10; $i++) {
                    $j = Http::withToken($token)->acceptJson()->timeout(30)->get($url)->throw()->json();
                    foreach ($j['value'] ?? [] as $x) {
                        $contacts[] = ['name' => $x['displayName'] ?? null, 'email' => $x['emailAddresses'][0]['address'] ?? null, 'phone' => $x['mobilePhone'] ?? ($x['businessPhones'][0] ?? null), 'company' => $x['companyName'] ?? null];
                    }
                    $url = $j['@odata.nextLink'] ?? null;
                }
            } else {
                $q = ['personFields' => 'names,emailAddresses,phoneNumbers,organizations', 'pageSize' => 500];
                for ($i = 0; $i < 10; $i++) {
                    $j = Http::withToken($token)->acceptJson()->timeout(30)->get('https://people.googleapis.com/v1/people/me/connections', $q)->throw()->json();
                    foreach ($j['connections'] ?? [] as $x) {
                        $contacts[] = ['name' => $x['names'][0]['displayName'] ?? null, 'email' => $x['emailAddresses'][0]['value'] ?? null, 'phone' => $x['phoneNumbers'][0]['value'] ?? null, 'company' => $x['organizations'][0]['name'] ?? null];
                    }
                    if (empty($j['nextPageToken'])) {
                        break;
                    }
                    $q['pageToken'] = $j['nextPageToken'];
                }
            }
        } catch (\Throwable $e) {
            return $this->fail(new \DomainException('Lecture des contacts impossible : '.$e->getMessage()));
        }
        $created = 0;
        $skipped = 0;
        $known = DB::table('partners')->where('company_id', app('tenant')->id)->whereNull('deleted_at')->whereNotNull('email')->pluck('email')->map(fn ($e) => mb_strtolower($e))->flip();
        foreach ($contacts as $x) {
            $email = $x['email'] ? mb_strtolower(trim($x['email'])) : null;
            $name = trim((string) ($x['name'] ?: $x['company'] ?: $email));
            if (! $name || ($email && isset($known[$email])) || ! $email) {
                $skipped++;

                continue;
            }
            $res = app(EntityWriter::class)->upsert(\App\Modules\Core\Models\Partner::class, (string) Str::uuid(), ['type' => 'customer', 'name' => mb_substr($name, 0, 190), 'email' => $email,
                'phone' => $x['phone'] ? mb_substr($x['phone'], 0, 40) : null, 'is_company' => ! $x['name'] && $x['company']]);
            if (($res['status'] ?? '') === 'applied') {
                $created++;
                $known[$email] = true;
            } else {
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'total' => count($contacts)];
    }

    /** Ordre d'affichage : réception, envoyés, brouillons, archives, puis les dossiers de l'utilisateur (A→Z), spam et corbeille à la fin. */
    public static function sortedFolders($folders)
    {
        $rank = ['inbox' => 0, 'sent' => 1, 'drafts' => 2, 'archive' => 3, 'other' => 4, 'junk' => 5, 'trash' => 6];

        return $folders->sortBy(fn ($f) => sprintf('%010d|%d|%s', $f->account_id, $rank[$f->role] ?? 4, mb_strtolower(\Illuminate\Support\Str::ascii($f->name))))->values();
    }

    public function storeFolder(Request $r)
    {
        $d = $r->validate(['account_id' => 'required|integer', 'name' => 'required|string|max:80', 'parent_id' => 'nullable|integer']);
        $a = $this->account($r, $d['account_id']);
        $parent = ! empty($d['parent_id']) ? Folder::where('account_id', $a->id)->findOrFail($d['parent_id']) : null;
        try {
            $f = $this->svc->createFolder($a, $d['name'], $parent);
        } catch (MailProtocolException|\DomainException $e) {
            return $this->fail($e);
        } finally {
            $this->svc->close();
        }

        return response()->json(['data' => self::folderRow($f)], 201);
    }

    public function updateFolder(Request $r, int $id)
    {
        $f = Folder::whereIn('account_id', $this->accountIds($r) ?: [0])->findOrFail($id);
        $d = $r->validate(['sync' => 'sometimes|boolean', 'name' => 'sometimes|string|max:80']);
        if (array_key_exists('sync', $d)) {
            $this->account($r, $f->account_id, true);
            $f->sync = $d['sync'];
            $f->save();
        }
        if (array_key_exists('name', $d) && $d['name'] !== $f->name) {
            try {
                $this->svc->renameFolder($f, $d['name']);
            } catch (MailProtocolException|\DomainException $e) {
                return $this->fail($e);
            } finally {
                $this->svc->close();
            }
        }

        return ['data' => self::folderRow($f->fresh())];
    }

    public function destroyFolder(Request $r, int $id)
    {
        $f = Folder::whereIn('account_id', $this->accountIds($r) ?: [0])->findOrFail($id);
        try {
            $this->svc->deleteFolder($f);
        } catch (MailProtocolException|\DomainException $e) {
            return $this->fail($e);
        } finally {
            $this->svc->close();
        }

        return response()->noContent();
    }

    // ------------------------------------------------------------------ signatures et modèles

    public function storeSignature(Request $r, ?int $id = null)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'html' => 'required|string|max:200000', 'fields' => 'nullable|array', 'is_default' => 'nullable|boolean',
            'on_reply' => 'nullable|boolean', 'account_id' => 'nullable|integer', 'company' => 'nullable|boolean']);
        $company = (bool) ($d['company'] ?? false);
        abort_if($company && ! $this->canManage($r), 403, 'Seul un gestionnaire peut créer une signature de société.');
        $c = app('tenant');
        if ($id) {
            $row = DB::table('mb_signatures')->where('company_id', $c->id)->where('id', $id)->first();
            abort_unless($row && ($row->user_id === $r->user()->id || ($row->user_id === null && $this->canManage($r))), 404);
            $company = $row->user_id === null;
        }
        $vals = ['name' => $d['name'], 'html' => Mime::sanitize($d['html']) ?? '', 'fields' => json_encode($d['fields'] ?? null), 'is_default' => (bool) ($d['is_default'] ?? false),
            'on_reply' => (bool) ($d['on_reply'] ?? true), 'account_id' => $d['account_id'] ?? null, 'updated_at' => now()];
        if ($vals['is_default']) {
            DB::table('mb_signatures')->where('company_id', $c->id)->where('user_id', $company ? null : $r->user()->id)->update(['is_default' => false]);
        }
        if ($id) {
            DB::table('mb_signatures')->where('id', $id)->update($vals);
        } else {
            $id = DB::table('mb_signatures')->insertGetId($vals + ['company_id' => $c->id, 'user_id' => $company ? null : $r->user()->id, 'created_at' => now()]);
        }
        $s = DB::table('mb_signatures')->find($id);

        return response()->json(['data' => self::sigRow($s)], $r->isMethod('post') ? 201 : 200);
    }

    protected static function sigRow(object $s): array
    {
        return array_merge((array) $s, ['fields' => json_decode((string) $s->fields, true), 'is_default' => (bool) $s->is_default, 'on_reply' => (bool) $s->on_reply,
            'user_id' => $s->user_id !== null ? (int) $s->user_id : null, 'account_id' => $s->account_id !== null ? (int) $s->account_id : null, 'id' => (int) $s->id]);
    }

    /**
     * Carnet d'adresses interne (comme la liste globale d'Outlook) : tous les collaborateurs et la direction,
     * avec l'adresse de leur boîte connectée à l'ERP. Un nouveau collaborateur (stagiaire compris) y apparaît
     * dès que son compte, sa fiche salarié ou sa boîte mail est ajouté.
     */
    protected function directory(\App\Models\Company $c): array
    {
        $out = [];
        $put = function (?string $email, ?string $name, ?string $label, string $kind) use (&$out) {
            $e = strtolower(trim((string) $email));
            if ($e === '' || ! filter_var($e, FILTER_VALIDATE_EMAIL)) {
                return;
            }
            $cur = $out[$e] ?? ['email' => $e, 'name' => '', 'label' => null, 'kinds' => []];
            $cur['name'] = $cur['name'] ?: trim((string) $name);
            $cur['label'] = $cur['label'] ?: ($label ?: null);
            $cur['kinds'][] = $kind;
            $out[$e] = $cur;
        };
        $users = DB::table('users')->where('company_id', $c->id)->where('is_active', true)->get(['id', 'name', 'email']);
        $userNames = $users->pluck('name', 'id');
        // Boîtes connectées : l'adresse professionnelle de chacun (et les boîtes partagées : direction, contact, compta…)
        foreach (Account::where('status', '!=', 'paused')->get(['email', 'display_name', 'user_id']) as $a) {
            $put($a->email, $a->display_name ?: ($a->user_id ? ($userNames[$a->user_id] ?? null) : null), $a->user_id ? null : 'Boîte partagée', 'mailbox');
        }
        if (\Illuminate\Support\Facades\Schema::hasTable('rh_employees')) {
            $depts = \Illuminate\Support\Facades\Schema::hasTable('rh_departments') ? DB::table('rh_departments')->where('company_id', $c->id)->pluck('name', 'id') : collect();
            foreach (DB::table('rh_employees')->where('company_id', $c->id)->where('active', true)->whereNull('deleted_at')->get(['first_name', 'last_name', 'email', 'job_title', 'department_id', 'department', 'staff_type', 'user_id']) as $e) {
                $label = trim(implode(' · ', array_filter([$e->job_title, $depts[$e->department_id] ?? $e->department, $e->staff_type === 'intern' ? 'Stagiaire' : null])));
                $name = trim($e->first_name.' '.$e->last_name);
                $put($e->email, $name, $label ?: null, 'employee');
                // Boîte de l'ERP rattachée au même utilisateur : fonction et service affichés aussi
                if ($e->user_id) {
                    foreach ($out as $k => $row) {
                        if (in_array('mailbox', $row['kinds'], true) && $row['label'] === null && ($row['name'] === $name || $row['name'] === ($userNames[$e->user_id] ?? ''))) {
                            $out[$k]['label'] = $label ?: null;
                        }
                    }
                }
            }
        }
        foreach ($users as $u) {
            $put($u->email, $u->name, null, 'user');
        }
        $rows = array_values(array_map(fn ($r) => $r + ['kinds' => array_values(array_unique($r['kinds']))], $out));
        usort($rows, fn ($a, $b) => strcasecmp($a['name'] ?: $a['email'], $b['name'] ?: $b['email']));

        return $rows;
    }

    /** Signatures attribuées à une boîte pour la personne connectée : nouveaux messages, réponses et transferts. */
    public function signatureDefaults(Request $r)
    {
        $d = $r->validate(['account_id' => 'required|integer', 'new_id' => 'nullable|integer', 'reply_id' => 'nullable|integer']);
        $a = $this->account($r, (int) $d['account_id']);
        $c = app('tenant');
        $mine = fn ($id) => $id === null || DB::table('mb_signatures')->where('company_id', $c->id)->where('id', $id)->where(fn ($q) => $q->where('user_id', $r->user()->id)->orWhereNull('user_id'))->exists();
        abort_unless($mine($d['new_id'] ?? null) && $mine($d['reply_id'] ?? null), 422, 'Signature introuvable.');
        DB::table('mb_signature_defaults')->updateOrInsert(['user_id' => $r->user()->id, 'account_id' => $a->id],
            ['company_id' => $c->id, 'new_id' => $d['new_id'] ?? null, 'reply_id' => $d['reply_id'] ?? null, 'updated_at' => now(), 'created_at' => now()]);

        return ['data' => ['account_id' => $a->id, 'new_id' => $d['new_id'] ?? null, 'reply_id' => $d['reply_id'] ?? null]];
    }

    public function updateSignature(Request $r, int $id)
    {
        return $this->storeSignature($r, $id);
    }

    public function destroySignature(Request $r, int $id)
    {
        $row = DB::table('mb_signatures')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($row && ($row->user_id === $r->user()->id || ($row->user_id === null && $this->canManage($r))), 404);
        DB::table('mb_signatures')->where('id', $id)->delete();

        return response()->noContent();
    }

    public function storeTemplate(Request $r, ?int $id = null)
    {
        $d = $r->validate(['name' => 'required|string|max:120', 'subject' => 'nullable|string|max:300', 'html' => 'required|string|max:300000', 'company' => 'nullable|boolean']);
        $c = app('tenant');
        $company = (bool) ($d['company'] ?? false) && $this->canManage($r);
        $vals = ['name' => $d['name'], 'subject' => $d['subject'] ?? null, 'html' => Mime::sanitize($d['html']) ?? '', 'updated_at' => now()];
        if ($id) {
            $row = DB::table('mb_templates')->where('company_id', $c->id)->where('id', $id)->first();
            abort_unless($row && ($row->user_id === $r->user()->id || ($row->user_id === null && $this->canManage($r))), 404);
            DB::table('mb_templates')->where('id', $id)->update($vals);
        } else {
            $id = DB::table('mb_templates')->insertGetId($vals + ['company_id' => $c->id, 'user_id' => $company ? null : $r->user()->id, 'created_at' => now()]);
        }

        return response()->json(['data' => DB::table('mb_templates')->find($id)], $r->isMethod('post') ? 201 : 200);
    }

    public function updateTemplate(Request $r, int $id)
    {
        return $this->storeTemplate($r, $id);
    }

    public function destroyTemplate(Request $r, int $id)
    {
        $row = DB::table('mb_templates')->where('company_id', app('tenant')->id)->where('id', $id)->first();
        abort_unless($row && ($row->user_id === $r->user()->id || ($row->user_id === null && $this->canManage($r))), 404);
        DB::table('mb_templates')->where('id', $id)->delete();

        return response()->noContent();
    }

    // ------------------------------------------------------------------ paramètres (gestionnaire)

    public function settings()
    {
        $c = app('tenant');
        $row = DB::table('mb_settings')->where('company_id', $c->id)->first();
        $ms = OAuth::app($c, 'microsoft');
        $g = OAuth::app($c, 'google');

        return ['data' => [
            'microsoft_client_id' => $row->microsoft_client_id ?? null, 'microsoft_tenant' => $row->microsoft_tenant ?? null, 'microsoft_secret_set' => ! empty($row?->microsoft_client_secret),
            'google_client_id' => $row->google_client_id ?? null, 'google_secret_set' => ! empty($row?->google_client_secret),
            'retention_days' => (int) ($row->retention_days ?? 90),
            'microsoft_ready' => ! empty($ms['client_id']) && ! empty($ms['client_secret']), 'microsoft_platform' => ! $ms['own'] && ! empty($ms['client_id']),
            'google_ready' => ! empty($g['client_id']) && ! empty($g['client_secret']), 'google_platform' => ! $g['own'] && ! empty($g['client_id']),
            'redirect_microsoft' => OAuth::redirectUri('microsoft'), 'redirect_google' => OAuth::redirectUri('google'),
            'ms_scopes' => OAuth::MS_SCOPES, 'google_scopes' => OAuth::GOOGLE_SCOPES,
        ]];
    }

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['microsoft_client_id' => 'nullable|string|max:80', 'microsoft_client_secret' => 'nullable|string|max:300', 'microsoft_tenant' => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9.-]+$/'],
            'google_client_id' => 'nullable|string|max:190', 'google_client_secret' => 'nullable|string|max:300', 'retention_days' => 'nullable|integer|min:7|max:3650']);
        $c = app('tenant');
        $vals = ['updated_at' => now()];
        foreach (['microsoft_client_id', 'microsoft_tenant', 'google_client_id'] as $k) {
            if (array_key_exists($k, $d)) {
                $vals[$k] = $d[$k] ? trim($d[$k]) : null;
            }
        }
        foreach (['microsoft_client_secret', 'google_client_secret'] as $k) {
            if (array_key_exists($k, $d) && $d[$k] !== null && ! str_starts_with($d[$k], '••••')) {
                $vals[$k] = $d[$k] === '' ? null : Crypt::encryptString(trim($d[$k]));
            }
        }
        if (isset($d['retention_days'])) {
            $vals['retention_days'] = $d['retention_days'];
        }
        DB::table('mb_settings')->updateOrInsert(['company_id' => $c->id], $vals + ['created_at' => now()]);

        return $this->settings();
    }
}
