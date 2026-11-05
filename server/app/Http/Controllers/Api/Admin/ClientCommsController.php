<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Plan;
use App\Models\PlatformAutomation;
use App\Models\PlatformCampaign;
use App\Models\PlatformDelivery;
use App\Platform\ClientComms;
use App\Services\SiteTraffic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Super admin › Communication clients : campagnes et relances automatiques vers les sociétés clientes. */
class ClientCommsController extends Controller
{
    public function __construct(protected ClientComms $comms) {}

    public function index()
    {
        ClientComms::ensureDefaults();

        return [
            'campaigns' => PlatformCampaign::latest('id')->limit(200)->get()->map(fn ($c) => $this->campaignArray($c)),
            'automations' => PlatformAutomation::orderBy('id')->get()->map(fn ($a) => $this->automationArray($a)),
            'segments' => ClientComms::SEGMENTS,
            'triggers' => collect(ClientComms::TRIGGERS)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0], 'days_label' => $t[1]])->values(),
            'variables' => ClientComms::VARIABLES,
            'plans' => Plan::where('is_active', true)->orderBy('sort')->get(['code', 'name']),
            'mail_ready' => \App\Messaging\MessagingService::platformMailReady() || filled(config('mail.mailers.'.config('mail.default').'.host')) || config('mail.default') !== 'smtp',
            'unsubscribed' => DB::table('platform_unsubscribes')->count(),
            'clients' => ClientComms::clients()->count(),
        ];
    }

    protected function campaignArray(PlatformCampaign $c): array
    {
        return $c->toArray() + ['status_label' => PlatformCampaign::STATUSES[$c->status] ?? $c->status, 'segment_label' => ClientComms::SEGMENTS[$c->audience['segment'] ?? 'all'] ?? ''];
    }

    protected function automationArray(PlatformAutomation $a): array
    {
        return $a->toArray() + ['days_label' => ClientComms::TRIGGERS[$a->trigger][1] ?? 'jours',
            'opened' => PlatformDelivery::where('automation_id', $a->id)->where('channel', 'email')->whereNotNull('opened_at')->count(),
            'emails' => PlatformDelivery::where('automation_id', $a->id)->where('channel', 'email')->where('status', 'sent')->count()];
    }

    /** Règles communes aux campagnes et aux relances. */
    protected function contentRules(): array
    {
        $url = ['nullable', 'string', 'max:500', 'regex:/^(https?:\/\/\S+|\{\{[a-z_]+\}\}\S*)$/i'];

        return [
            'channels' => 'required|array|min:1',
            'channels.*' => 'in:notification,email',
            'subject' => 'nullable|string|max:190',
            'preheader' => 'nullable|string|max:190',
            'title' => 'nullable|string|max:190',
            'body_text' => 'nullable|string|max:20000',
            'format' => 'required|in:text,design,html',
            'blocks' => 'nullable|array|max:120',
            'style' => 'nullable|array',
            'html' => 'nullable|string|max:400000',
            'cta_label' => 'nullable|string|max:80',
            'cta_url' => $url,
        ];
    }

    protected function campaignData(Request $r): array
    {
        $d = $r->validate([
            'name' => 'required|string|max:190',
            'audience' => 'required|array',
            'audience.segment' => ['required', Rule::in(array_keys(ClientComms::SEGMENTS))],
            'audience.plans' => 'nullable|array',
            'audience.plans.*' => 'string|max:40',
            'audience.days' => 'nullable|integer|min:1|max:365',
            'audience.company_ids' => 'nullable|array|max:2000',
            'audience.company_ids.*' => 'integer',
            'audience.recipients' => 'nullable|in:admins,all',
            'schedule' => 'required|in:now,once,recurring',
            'send_at' => 'nullable|date|required_if:schedule,once',
            'recurrence' => 'nullable|array|required_if:schedule,recurring',
            'recurrence.freq' => 'nullable|in:daily,weekly,monthly',
            'recurrence.time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'recurrence.weekday' => 'nullable|integer|min:1|max:7',
            'recurrence.day' => 'nullable|integer|min:1|max:28',
            'recurrence.until' => 'nullable|date',
        ] + $this->contentRules());
        $this->checkContent($d);

        return $d;
    }

    protected function checkContent(array $d): void
    {
        $email = in_array('email', $d['channels'], true);
        $err = match (true) {
            $email && blank($d['subject'] ?? null) => ['subject' => 'Indiquez l\'objet de l\'e-mail.'],
            in_array('notification', $d['channels'], true) && blank($d['title'] ?? null) && blank($d['subject'] ?? null) => ['title' => 'Indiquez le titre de la notification.'],
            $d['format'] === 'html' && $email && blank($d['html'] ?? null) => ['html' => 'Collez le code HTML de l\'e-mail.'],
            $d['format'] === 'design' && $email && empty($d['blocks']) => ['blocks' => 'Ajoutez au moins un bloc à l\'e-mail.'],
            $d['format'] === 'text' && blank($d['body_text'] ?? null) => ['body_text' => 'Écrivez le message.'],
            filled($d['cta_url'] ?? null) xor filled($d['cta_label'] ?? null) => ['cta_label' => 'Indiquez le texte et le lien du bouton (ou aucun des deux).'],
            default => null,
        };
        if ($err) {
            throw \Illuminate\Validation\ValidationException::withMessages($err);
        }
    }

    public function show(int $id)
    {
        $c = PlatformCampaign::findOrFail($id);
        $this->comms->refreshStats($c);
        $rows = PlatformDelivery::where('campaign_id', $id)->latest('id')->limit(300)->get();
        $names = Company::withoutGlobalScopes()->whereIn('id', $rows->pluck('company_id')->unique())->pluck('name', 'id');

        return ['data' => $this->campaignArray($c->fresh()), 'deliveries' => $rows->map(fn ($d) => $d->only(['id', 'run', 'company_id', 'channel', 'email', 'status', 'error', 'sent_at', 'opened_at', 'clicked_at'])
            + ['company' => $names[$d->company_id] ?? '—'])];
    }

    public function store(Request $r)
    {
        $d = $this->campaignData($r);
        $c = PlatformCampaign::create($d + ['status' => 'draft', 'created_by' => $r->user()->id]);

        return response()->json(['data' => $this->campaignArray($c)], 201);
    }

    public function update(Request $r, int $id)
    {
        $c = PlatformCampaign::findOrFail($id);
        abort_if(in_array($c->status, ['sending', 'sent'], true) && $c->schedule !== 'recurring', 422, 'Une campagne envoyée ne se modifie plus : dupliquez-la.');
        $c->update($this->campaignData($r));
        if ($c->status === 'scheduled') {
            $this->schedule($c);
        }

        return ['data' => $this->campaignArray($c->fresh())];
    }

    public function destroy(int $id)
    {
        $c = PlatformCampaign::findOrFail($id);
        abort_if($c->status === 'sending', 422, 'Mettez d\'abord la campagne en pause.');
        PlatformDelivery::where('campaign_id', $id)->where('status', 'pending')->update(['status' => 'skipped', 'error' => 'Campagne supprimée']);
        $c->delete();

        return ['ok' => true];
    }

    protected function schedule(PlatformCampaign $c): void
    {
        if ($c->schedule === 'once') {
            abort_if(! $c->send_at || $c->send_at->lt(now()->subMinutes(5)), 422, 'Choisissez une date d\'envoi à venir.');
            $c->forceFill(['status' => 'scheduled', 'next_run_at' => $c->send_at])->save();
        } else {
            $next = $c->nextOccurrence();
            abort_if(! $next, 422, 'La date de fin de la récurrence est déjà passée.');
            $c->forceFill(['status' => 'scheduled', 'next_run_at' => $next])->save();
        }
    }

    /** Envoyer maintenant, ou programmer (date unique / récurrence). */
    public function launch(int $id)
    {
        $c = PlatformCampaign::findOrFail($id);
        abort_if(in_array($c->status, ['sending', 'sent'], true) && $c->schedule !== 'recurring', 422, 'Cette campagne est déjà envoyée : dupliquez-la pour la renvoyer.');
        if ($c->schedule === 'now') {
            $out = $this->comms->launch($c);
            $this->comms->sendPending(); // premier lot tout de suite, la suite par la tâche planifiée

            return ['data' => $this->campaignArray($c->fresh()), 'result' => $out];
        }
        $this->schedule($c);

        return ['data' => $this->campaignArray($c->fresh())];
    }

    public function pause(int $id)
    {
        $c = PlatformCampaign::findOrFail($id);
        abort_unless(in_array($c->status, ['scheduled', 'sending'], true), 422, 'Rien à mettre en pause.');
        $c->forceFill(['status' => 'paused', 'stats' => ($c->stats ?? []) + ['paused_from' => $c->status]])->save();

        return ['data' => $this->campaignArray($c)];
    }

    public function resume(int $id)
    {
        $c = PlatformCampaign::findOrFail($id);
        abort_unless($c->status === 'paused', 422, 'La campagne n\'est pas en pause.');
        if (PlatformDelivery::where('campaign_id', $id)->where('status', 'pending')->exists()) {
            $c->forceFill(['status' => 'sending'])->save();
        } elseif ($c->schedule === 'now') {
            $c->forceFill(['status' => 'sent'])->save();
        } else {
            $this->schedule($c);
        }

        return ['data' => $this->campaignArray($c->fresh())];
    }

    public function duplicate(Request $r, int $id)
    {
        $c = PlatformCampaign::findOrFail($id);
        $copy = $c->replicate(['status', 'runs', 'stats', 'last_run_at', 'next_run_at']);
        $copy->forceFill(['name' => mb_substr($c->name.' (copie)', 0, 190), 'status' => 'draft', 'runs' => 0, 'created_by' => $r->user()->id])->save();

        return response()->json(['data' => $this->campaignArray($copy)], 201);
    }

    public function audience(Request $r)
    {
        $a = $r->validate(['segment' => ['required', Rule::in(array_keys(ClientComms::SEGMENTS))], 'plans' => 'nullable|array', 'days' => 'nullable|integer|min:1|max:365',
            'company_ids' => 'nullable|array', 'company_ids.*' => 'integer', 'recipients' => 'nullable|in:admins,all']);

        return $this->comms->count($a);
    }

    /** Liste des sociétés clientes (sélection manuelle d'une audience). */
    public function companies(Request $r)
    {
        $q = trim((string) $r->query('q', ''));

        return ['data' => ClientComms::clients()->with('plan')->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('name', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%')))
            ->orderBy('name')->limit(100)->get()->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'plan' => $c->plan?->name, 'status' => $c->status])];
    }

    /** Société exemple pour l'aperçu : une cliente réelle si possible. */
    protected function sample(array $audience = []): Company
    {
        $c = ($audience ? ClientComms::audienceQuery($audience)->first() : null) ?? ClientComms::clients()->first() ?? SiteTraffic::editorCompany();

        return $c ?? new Company(['name' => 'Boutique Exemple']);
    }

    protected function draft(array $d, string $class = PlatformCampaign::class): PlatformCampaign|PlatformAutomation
    {
        return new $class(collect($d)->only(['subject', 'preheader', 'title', 'body_text', 'format', 'blocks', 'style', 'html', 'cta_label', 'cta_url', 'channels'])->all() + ['name' => 'Aperçu']);
    }

    public function preview(Request $r)
    {
        $d = $r->validate($this->contentRules() + ['audience' => 'nullable|array', 'channels' => 'nullable|array']);
        $d['channels'] ??= ['email'];
        $m = $this->draft($d);
        $company = $this->sample((array) ($d['audience'] ?? []));
        $user = ClientComms::recipients($company)->first() ?? $r->user();
        $vars = ClientComms::vars($company, $user, '#');

        return [
            'html' => $this->comms->emailHtml($m, $vars),
            'subject' => \App\Modules\Marketing\EmailRenderer::vars((string) ($m->subject ?: $m->title), $vars),
            'title' => \App\Modules\Marketing\EmailRenderer::vars((string) ($m->title ?: $m->subject), $vars),
            'text' => ClientComms::plain($m, $vars),
            'company' => $company->name,
        ];
    }

    /** E-mail de test au super admin (et notification de test dans sa propre application). */
    public function test(Request $r)
    {
        $d = $r->validate($this->contentRules() + ['to' => 'nullable|email']);
        $this->checkContent($d);
        $m = $this->draft($d);
        $company = $this->sample();
        $vars = ClientComms::vars($company, $r->user(), '#');
        $sent = [];
        if (in_array('notification', $d['channels'], true)) {
            \App\Models\AppNotification::send($r->user()->company_id, [$r->user()->id], 'announcement', '[Test] '.mb_substr(\App\Modules\Marketing\EmailRenderer::vars((string) ($m->title ?: $m->subject), $vars), 0, 240), ClientComms::plain($m, $vars), 'announcement', 'preview');
            $sent[] = 'notification';
        }
        if (in_array('email', $d['channels'], true)) {
            $to = $d['to'] ?? $r->user()->email;
            try {
                ClientComms::mail($to, $r->user()->name, '[Test] '.\App\Modules\Marketing\EmailRenderer::vars((string) $m->subject, $vars), $this->comms->emailHtml($m, $vars), ClientComms::plain($m, $vars));
                $sent[] = 'email';
            } catch (\Throwable $e) {
                return response()->json(['message' => 'E-mail non envoyé : '.$e->getMessage().' Vérifiez le serveur d\'e-mails (Ventes de modules › Serveur e-mail).'], 422);
            }
        }

        return ['sent' => $sent, 'to' => $d['to'] ?? $r->user()->email];
    }

    // ------------------------------------------------------------------ relances

    public function updateAutomation(Request $r, int $id)
    {
        $a = PlatformAutomation::findOrFail($id);
        $d = $r->validate(['name' => 'required|string|max:190', 'days' => 'required|integer|min:0|max:365', 'recipients' => 'required|in:admins,all', 'is_active' => 'sometimes|boolean'] + $this->contentRules());
        $this->checkContent($d);
        $a->update($d);

        return ['data' => $this->automationArray($a->fresh())];
    }

    public function toggleAutomation(Request $r, int $id)
    {
        $a = PlatformAutomation::findOrFail($id);
        $a->update(['is_active' => (bool) $r->validate(['is_active' => 'required|boolean'])['is_active']]);

        return ['data' => $this->automationArray($a)];
    }

    /** Sociétés qui recevraient cette relance au prochain passage. */
    public function automationTargets(int $id)
    {
        $a = PlatformAutomation::findOrFail($id);
        $rows = $this->comms->automationTargets($a);

        return ['count' => $rows->count(), 'sample' => $rows->take(20)->map(fn ($r) => ['id' => $r[0]->id, 'name' => $r[0]->name, 'plan' => $r[0]->plan?->name])->values()];
    }
}
