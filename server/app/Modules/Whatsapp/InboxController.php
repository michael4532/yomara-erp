<?php

namespace App\Modules\Whatsapp;

use App\Http\Controllers\Controller;
use App\Messaging\Phone;
use App\Models\User;
use App\Modules\Whatsapp\Models\Conversation;
use App\Modules\Whatsapp\Models\WaMessage;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class InboxController extends Controller
{
    public function __construct(protected Inbox $inbox) {}

    protected function manager(Request $r): bool
    {
        return $r->user()->hasPermission('whatsapp.manage');
    }

    protected function scope(Request $r)
    {
        $q = Conversation::query();
        if (! $this->manager($r)) {
            $q->where(fn ($w) => $w->where('assigned_to', $r->user()->id)->orWhereNull('assigned_to'));
        }

        return $q;
    }

    protected function conv(Request $r, int $id): Conversation
    {
        return $this->scope($r)->findOrFail($id);
    }

    protected function fail(\Throwable $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    public static function convRow(Conversation $c, array $users = []): array
    {
        return ['id' => $c->id, 'phone' => $c->phone, 'name' => $c->name, 'partner_id' => $c->partner_id, 'assigned_to' => $c->assigned_to, 'assignee' => $users[$c->assigned_to] ?? null,
            'status' => $c->status, 'labels' => $c->labels ?? [], 'unread' => $c->unread, 'last_preview' => $c->last_preview, 'last_direction' => $c->last_direction,
            'last_message_at' => $c->last_message_at?->toIso8601String(), 'window_open' => $c->windowOpen(),
            'window_closes_at' => $c->last_inbound_at?->copy()->addHours(24)->toIso8601String()];
    }

    public static function msgRow(WaMessage $m, array $users = []): array
    {
        return ['id' => $m->id, 'direction' => $m->direction, 'type' => $m->type, 'body' => $m->body, 'file_name' => $m->file_name, 'mime' => $m->media_mime,
            'media_url' => $m->media_path ? URL::temporarySignedRoute('whatsapp.media', now()->addHours(12), ['id' => $m->id]) : null,
            'status' => $m->status, 'error' => $m->error, 'user' => $m->user_id ? ($users[$m->user_id] ?? null) : null, 'auto' => $m->auto, 'template' => $m->template,
            'at' => $m->created_at?->toIso8601String()];
    }

    protected function users(): array
    {
        return DB::table('users')->where('company_id', app('tenant')->id)->pluck('name', 'id')->all();
    }

    public function index(Request $r)
    {
        $c = app('tenant');
        $s = $this->inbox->settings($c);
        $ch = $this->inbox->channel($c);
        $agents = User::where('company_id', $c->id)->where('is_active', true)->orderBy('name')->get()->filter(fn ($u) => $u->hasPermission('whatsapp.use') || $u->hasPermission('whatsapp.manage'))
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values();
        $base = $this->scope($r);
        $phone = $s->display_phone ? Phone::digits($s->display_phone) : null;

        return ['data' => [
            'provider' => $ch['provider'], 'provider_label' => \App\Messaging\MessagingService::PROVIDERS['whatsapp'][$ch['provider']]['label'] ?? null,
            'can_manage' => $this->manager($r), 'me' => $r->user()->id,
            'webhook_url' => $this->manager($r) ? url('/api/whatsapp-webhook/'.$s->webhook_token) : null, 'verify_token' => $this->manager($r) ? $s->verify_token : null,
            'display_phone' => $s->display_phone, 'click_to_chat' => $phone ? 'https://wa.me/'.$phone : null,
            'auto_assign' => (bool) $s->auto_assign, 'welcome_message' => $s->welcome_message, 'away_message' => $s->away_message, 'hours' => json_decode((string) $s->hours, true),
            'open_now' => $this->inbox->isOpen($s),
            'templates' => DB::table('wa_templates')->where('company_id', $c->id)->orderBy('name')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'language' => $t->language,
                'category' => $t->category, 'status' => $t->status, 'body' => $t->body, 'params' => (int) $t->params]),
            'quick_replies' => DB::table('wa_quick_replies')->where('company_id', $c->id)->orderBy('shortcut')->get(['id', 'shortcut', 'body']),
            'agents' => $agents,
            'counts' => [
                'mine' => (clone $base)->where('assigned_to', $r->user()->id)->where('status', '!=', 'closed')->count(),
                'unassigned' => (clone $base)->whereNull('assigned_to')->where('status', '!=', 'closed')->count(),
                'unread' => (clone $base)->where('unread', '>', 0)->count(),
            ],
        ]];
    }

    public function conversations(Request $r)
    {
        $q = $this->scope($r);
        match ($r->query('filter', 'all')) {
            'mine' => $q->where('assigned_to', $r->user()->id)->where('status', '!=', 'closed'),
            'unassigned' => $q->whereNull('assigned_to')->where('status', '!=', 'closed'),
            'unread' => $q->where('unread', '>', 0),
            'closed' => $q->where('status', 'closed'),
            default => $q->where('status', '!=', 'closed'),
        };
        if ($s = trim((string) $r->query('q'))) {
            $digits = preg_replace('/\D/', '', $s);
            $q->where(fn ($w) => $w->where('name', 'like', '%'.$s.'%')->orWhere('last_preview', 'like', '%'.$s.'%')->when(strlen($digits) >= 3, fn ($w) => $w->orWhere('phone', 'like', '%'.$digits.'%')));
        }
        $users = $this->users();

        return ['data' => $q->orderByDesc('last_message_at')->limit(200)->get()->map(fn ($c) => self::convRow($c, $users))->values()];
    }

    public function show(Request $r, int $id)
    {
        $conv = $this->conv($r, $id);
        $q = WaMessage::where('conversation_id', $conv->id);
        if ($r->filled('after')) {
            $q->where('id', '>', (int) $r->query('after'))->orderBy('id');
            $msgs = $q->limit(200)->get();
        } else {
            $msgs = $q->orderByDesc('id')->limit((int) min(300, max(20, (int) $r->query('limit', 100))))->get()->reverse()->values();
        }
        if ($conv->unread > 0 && ! $r->boolean('peek')) {
            $conv->forceFill(['unread' => 0])->save();
        }
        $users = $this->users();
        $partner = $conv->partner_id ? DB::table('partners')->where('company_id', $conv->company_id)->where('id', $conv->partner_id)->first(['id', 'name', 'email', 'phone', 'type']) : null;
        $stats = $partner ? [
            'invoices_due' => (float) DB::table('trade_documents')->where('company_id', $conv->company_id)->where('partner_id', $partner->id)->where('direction', 'sale')
                ->whereIn('type', ['invoice'])->where('status', 'validated')->whereNull('deleted_at')->sum(DB::raw('total - amount_paid')),
            'last_invoice' => DB::table('trade_documents')->where('company_id', $conv->company_id)->where('partner_id', $partner->id)->where('direction', 'sale')->where('type', 'invoice')
                ->whereNull('deleted_at')->orderByDesc('date')->value('number'),
        ] : null;

        return ['data' => self::convRow($conv, $users), 'messages' => $msgs->map(fn ($m) => self::msgRow($m, $users))->values(), 'partner' => $partner, 'stats' => $stats];
    }

    public function send(Request $r, int $id)
    {
        $conv = $this->conv($r, $id);
        $d = $r->validate(['body' => 'nullable|string|max:4096', 'template' => 'nullable|string|max:120', 'params' => 'nullable|array|max:10', 'params.*' => 'nullable|string|max:500',
            'language' => 'nullable|string|max:10', 'file' => 'nullable|file|max:16384']);
        abort_if(empty($d['body']) && empty($d['template']) && ! $r->hasFile('file'), 422, 'Message vide.');
        $file = $r->hasFile('file') ? [$r->file('file')->get(), $r->file('file')->getMimeType(), $r->file('file')->getClientOriginalName()] : null;
        if ($file && ! in_array(strtolower(pathinfo((string) $file[2], PATHINFO_EXTENSION)) === 'jpeg' ? 'jpg' : strtolower(pathinfo((string) $file[2], PATHINFO_EXTENSION)), \App\Modules\Whatsapp\Inbox::SAFE_EXT, true)) {
            return $this->fail(new \DomainException('Ce type de fichier ne peut pas être envoyé.'));
        }
        try {
            if (! $conv->assigned_to) {
                $conv->forceFill(['assigned_to' => $r->user()->id])->save(); // qui répond prend la conversation
            }
            $m = $this->inbox->send($conv, $r->user()->id, array_filter(['body' => $d['body'] ?? null, 'template' => $d['template'] ?? null, 'params' => $d['params'] ?? null,
                'language' => $d['language'] ?? null, 'file' => $file]));
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['data' => self::msgRow($m, $this->users()), 'conversation' => self::convRow($conv->fresh(), $this->users())], 201);
    }

    /** Nouvelle conversation vers un numéro (modèle obligatoire avec l'API Cloud de Meta). */
    public function start(Request $r)
    {
        $d = $r->validate(['phone' => 'required|string|max:30', 'name' => 'nullable|string|max:190', 'body' => 'nullable|string|max:4096', 'template' => 'nullable|string|max:120',
            'params' => 'nullable|array|max:10', 'language' => 'nullable|string|max:10', 'partner_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('partners')]]);
        $phone = Phone::digits($d['phone']);
        abort_unless($phone, 422, 'Numéro invalide.');
        $c = app('tenant');
        $conv = $this->inbox->conversation($c, $phone, $d['name'] ?? null);
        if (! empty($d['partner_id'])) {
            $conv->forceFill(['partner_id' => $d['partner_id']])->save();
        }
        $conv->forceFill(['assigned_to' => $conv->assigned_to ?: $r->user()->id])->save();
        try {
            $this->inbox->send($conv, $r->user()->id, array_filter(['body' => $d['body'] ?? null, 'template' => $d['template'] ?? null, 'params' => $d['params'] ?? null, 'language' => $d['language'] ?? null]));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage(), 'conversation' => self::convRow($conv, $this->users())], 422);
        }

        return response()->json(['data' => self::convRow($conv->fresh(), $this->users())], 201);
    }

    public function update(Request $r, int $id)
    {
        $conv = $this->conv($r, $id);
        $d = $r->validate(['assigned_to' => ['nullable', 'integer', \App\Support\TenantRef::of('users')], 'status' => 'nullable|in:open,pending,closed', 'labels' => 'nullable|array|max:10', 'labels.*' => 'string|max:30',
            'name' => 'nullable|string|max:190', 'partner_id' => ['nullable', 'uuid', \App\Support\TenantRef::of('partners')], 'unread' => 'nullable|boolean']);
        if ($r->has('assigned_to')) {
            abort_unless($this->manager($r) || in_array($d['assigned_to'], [null, $r->user()->id], true) || $conv->assigned_to === $r->user()->id, 403, 'Seul un responsable peut attribuer à un collègue.');
            $conv->assigned_to = $d['assigned_to'];
            if ($d['assigned_to'] && $d['assigned_to'] !== $r->user()->id) {
                try {
                    \App\Models\AppNotification::send($conv->company_id, [$d['assigned_to']], 'whatsapp', 'Conversation WhatsApp attribuée', $conv->name.' · '.$conv->last_preview, 'whatsapp', (string) $conv->id);
                } catch (\Throwable) {
                }
            }
        }
        foreach (['status', 'labels', 'name', 'partner_id'] as $k) {
            if (array_key_exists($k, $d)) {
                $conv->{$k} = $d[$k];
            }
        }
        if ($r->boolean('unread')) {
            $conv->unread = max(1, $conv->unread);
        }
        $conv->save();

        return ['data' => self::convRow($conv, $this->users())];
    }

    /** Crée la fiche client à partir de la conversation. */
    public function createPartner(Request $r, int $id)
    {
        $conv = $this->conv($r, $id);
        abort_if($conv->partner_id, 422, 'Cette conversation est déjà reliée à une fiche.');
        $d = $r->validate(['name' => 'nullable|string|max:190', 'email' => 'nullable|email']);
        $res = app(EntityWriter::class)->upsert(\App\Modules\Core\Models\Partner::class, (string) Str::uuid(), ['type' => 'customer', 'name' => $d['name'] ?? $conv->name ?? '+'.$conv->phone,
            'phone' => '+'.$conv->phone, 'email' => $d['email'] ?? null]);
        if (($res['status'] ?? '') !== 'applied') {
            return response()->json(['message' => $res['message'] ?? 'Création impossible.', 'errors' => $res['errors'] ?? null], 422);
        }
        $conv->forceFill(['partner_id' => $res['record']['id'], 'name' => $res['record']['name']])->save();

        return ['data' => self::convRow($conv, $this->users())];
    }

    /** Pièce d'une conversation (lien signé pour l'application). */
    public function media(int $id)
    {
        $m = WaMessage::withoutGlobalScopes()->findOrFail($id);
        abort_unless($m->media_path && Storage::disk('local')->exists($m->media_path), 404);
        $full = Storage::disk('local')->path($m->media_path);
        // type déclaré, mais jamais affiché s'il n'est pas sûr (SVG, HTML… : téléchargement) ; nosniff + bac à sable
        $mime = $m->media_mime ?: ((new \finfo(FILEINFO_MIME_TYPE))->file($full) ?: 'application/octet-stream');

        return response()->file($full, \App\Support\SafeDownload::headers($mime, (string) ($m->file_name ?: basename($m->media_path)), true));
    }

    // ------------------------------------------------------------------ paramètres, modèles, réponses rapides

    public function saveSettings(Request $r)
    {
        $d = $r->validate(['display_phone' => 'nullable|string|max:30', 'auto_assign' => 'nullable|boolean', 'welcome_message' => 'nullable|string|max:1000', 'away_message' => 'nullable|string|max:1000',
            'hours' => 'nullable|array']);
        $s = $this->inbox->settings(app('tenant'));
        $u = ['updated_at' => now()];
        foreach (['display_phone', 'welcome_message', 'away_message'] as $k) {
            if (array_key_exists($k, $d)) {
                $u[$k] = $d[$k] ?: null;
            }
        }
        if (array_key_exists('auto_assign', $d)) {
            $u['auto_assign'] = (bool) $d['auto_assign'];
        }
        if (isset($d['hours'])) {
            $h = [];
            foreach (range(1, 7) as $i) {
                $v = $d['hours'][(string) $i] ?? null;
                $h[(string) $i] = is_array($v) && count($v) === 2 && preg_match('/^\d{2}:\d{2}$/', (string) $v[0]) && preg_match('/^\d{2}:\d{2}$/', (string) $v[1]) && $v[0] < $v[1] ? [$v[0], $v[1]] : null;
            }
            $u['hours'] = json_encode($h);
        }
        DB::table('wa_settings')->where('id', $s->id)->update($u);

        return $this->index($r);
    }

    public function syncTemplates()
    {
        try {
            return ['count' => $this->inbox->syncTemplates(app('tenant'))];
        } catch (\DomainException $e) {
            return $this->fail($e);
        }
    }

    public function createTemplate(Request $r)
    {
        $d = $r->validate(['name' => 'required|string|max:100', 'category' => 'required|in:MARKETING,UTILITY', 'body' => 'required|string|max:1024', 'language' => 'nullable|string|max:10',
            'examples' => 'nullable|array']);
        try {
            $this->inbox->createTemplate(app('tenant'), $d['name'], $d['category'], $d['body'], $d['language'] ?? 'fr', $d['examples'] ?? []);
        } catch (\DomainException $e) {
            return $this->fail($e);
        }

        return response()->json(['ok' => true], 201);
    }

    public function storeQuick(Request $r, ?int $id = null)
    {
        $d = $r->validate(['shortcut' => 'required|string|max:40', 'body' => 'required|string|max:2000']);
        $c = app('tenant');
        $vals = ['shortcut' => ltrim(trim($d['shortcut']), '/'), 'body' => $d['body'], 'updated_at' => now()];
        if ($id) {
            DB::table('wa_quick_replies')->where('company_id', $c->id)->where('id', $id)->update($vals);
        } else {
            $id = DB::table('wa_quick_replies')->insertGetId($vals + ['company_id' => $c->id, 'created_at' => now()]);
        }

        return response()->json(['data' => DB::table('wa_quick_replies')->find($id)], 201);
    }

    public function updateQuick(Request $r, int $id)
    {
        return $this->storeQuick($r, $id);
    }

    public function destroyQuick(int $id)
    {
        DB::table('wa_quick_replies')->where('company_id', app('tenant')->id)->where('id', $id)->delete();

        return response()->noContent();
    }
}
