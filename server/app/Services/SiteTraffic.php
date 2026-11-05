<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Site de présentation : mesure d'audience sans cookie (empreinte journalière anonymisée)
 * et transfert des personnes intéressées vers le CRM / Marketing de la société de l'éditeur.
 */
class SiteTraffic
{
    public const EVENTS = ['view' => 'Visites', 'signup_form' => 'Formulaire d\'inscription ouvert', 'signup' => 'Inscriptions', 'download' => 'Téléchargements', 'contact' => 'Demandes de contact'];

    public static function record(Request $r, string $event = 'view', ?string $detail = null): void
    {
        try {
            $ua = (string) $r->userAgent();
            // Les robots sont ignorés pour les visites ; une inscription ou une demande envoyée compte toujours.
            if (! in_array($event, ['signup', 'contact'], true) && ($ua === '' || preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|monitor|curl|wget|python|headless|lighthouse/i', $ua))) {
                return;
            }
            $ref = (string) $r->headers->get('referer', '');
            $refHost = $ref ? strtolower((string) parse_url($ref, PHP_URL_HOST)) : '';
            if ($refHost === strtolower($r->getHost())) {
                $refHost = '';
            }
            $utm = $r->query('utm_source');
            DB::table('site_visits')->insert([
                'day' => now()->toDateString(),
                'visitor' => hash('sha256', $r->ip().'|'.$ua.'|'.now()->toDateString().'|'.config('app.key')),
                'event' => $event,
                'path' => mb_substr('/'.ltrim($r->path(), '/'), 0, 190),
                'referrer' => $refHost ? mb_substr($refHost, 0, 190) : null,
                'source' => mb_substr((string) ($utm ?: self::sourceOf($refHost)), 0, 60) ?: null,
                'campaign' => $r->query('utm_campaign') ? mb_substr((string) $r->query('utm_campaign'), 0, 120) : null,
                'device' => preg_match('/ipad|tablet/i', $ua) ? 'tablette' : (preg_match('/mobi|android|iphone/i', $ua) ? 'mobile' : 'ordinateur'),
                'os' => match (true) {
                    (bool) preg_match('/android/i', $ua) => 'Android',
                    (bool) preg_match('/iphone|ipad|ipod/i', $ua) => 'iOS',
                    (bool) preg_match('/windows/i', $ua) => 'Windows',
                    (bool) preg_match('/mac os/i', $ua) => 'macOS',
                    (bool) preg_match('/linux/i', $ua) => 'Linux',
                    default => 'Autre',
                },
                'detail' => $detail ? mb_substr($detail, 0, 120) : null,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // la mesure d'audience ne doit jamais bloquer le site
        }
    }

    protected static function sourceOf(string $host): string
    {
        if ($host === '') {
            return 'direct';
        }
        foreach (['google' => 'Google', 'bing' => 'Bing', 'facebook' => 'Facebook', 'fb.' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'lnkd' => 'LinkedIn',
            'whatsapp' => 'WhatsApp', 'wa.me' => 'WhatsApp', 't.co' => 'X / Twitter', 'twitter' => 'X / Twitter', 'x.com' => 'X / Twitter', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'yahoo' => 'Yahoo'] as $k => $v) {
            if (str_contains($host, $k)) {
                return $v;
            }
        }

        return $host;
    }

    /** Société de l'éditeur (celle du premier super admin) : reçoit les prospects dans son CRM / Marketing. */
    public static function editorCompany(): ?Company
    {
        $id = User::where('is_super_admin', true)->orderBy('id')->value('company_id');

        return $id ? Company::find($id) : null;
    }

    /** Ajoute (ou met à jour) la personne dans le Marketing et, pour une demande, crée une opportunité CRM. */
    public static function capture(string $kind, array $p): void
    {
        try {
            $c = self::editorCompany();
            $email = mb_strtolower(trim((string) ($p['email'] ?? ''))) ?: null;
            $phone = trim((string) ($p['phone'] ?? '')) ?: null;
            if (! $c || (! $email && ! $phone)) {
                return;
            }
            $name = trim((string) ($p['name'] ?? ''));
            [$first, $last] = array_pad(explode(' ', $name, 2), 2, null);
            $listName = ['signup' => 'Site — inscrits', 'contact' => 'Site — demandes'][$kind] ?? 'Site — prospects';
            if ($c->hasModule('marketing') && Schema::hasTable('mk_contacts')) {
                $contact = \App\Modules\Marketing\Models\Contact::withoutGlobalScopes()->where('company_id', $c->id)->where($email ? 'email' : 'phone', $email ?? $phone)->first()
                    ?? new \App\Modules\Marketing\Models\Contact;
                $tags = mb_substr(implode(', ', array_values(array_unique(array_filter(array_merge(array_map('trim', explode(',', (string) $contact->tags)), ['site', $kind], (array) ($p['tags'] ?? []), ))))), 0, 190);
                $contact->forceFill([
                    'company_id' => $c->id, 'first_name' => $contact->first_name ?: $first, 'last_name' => $contact->last_name ?: $last,
                    'company_name' => ($p['company'] ?? null) ?: $contact->company_name, 'email' => $email ?? $contact->email, 'phone' => $phone ?: $contact->phone,
                    'source' => $contact->source ?: 'site', 'tags' => $tags, 'last_activity_at' => now(),
                ])->save();
                $list = \App\Modules\Marketing\Models\MkList::withoutGlobalScopes()->where('company_id', $c->id)->where('name', $listName)->first();
                if (! $list) {
                    $list = (new \App\Modules\Marketing\Models\MkList)->forceFill(['company_id' => $c->id, 'name' => $listName, 'description' => 'Rempli automatiquement depuis le site de présentation']);
                    $list->save();
                }
                $list->contacts()->syncWithoutDetaching([$contact->id]);
            }
            if ($c->hasModule('crm') && Schema::hasTable('crm_leads')) {
                $stage = \App\Modules\Crm\Models\Stage::withoutGlobalScopes()->where('company_id', $c->id)->where('kind', 'open')->orderBy('sequence')->first();
                (new \App\Modules\Crm\Models\Lead)->forceFill([
                    'company_id' => $c->id,
                    'title' => ($kind === 'signup' ? 'Inscription en ligne : ' : (($p['label'] ?? 'Demande').' : ')).(($p['company'] ?? null) ?: $name),
                    'contact_name' => $name ?: null, 'company_name' => $p['company'] ?? null, 'email' => $email, 'phone' => $phone,
                    'source' => 'Site internet', 'stage_id' => $stage?->id, 'notes' => $p['message'] ?? null, 'tags' => 'site, '.$kind,
                ])->save();
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Statistiques pour la console de l'éditeur. */
    public static function analytics(int $days = 30): array
    {
        if (! Schema::hasTable('site_visits')) {
            return [];
        }
        $from = now()->subDays($days - 1)->startOfDay();
        $q = fn () => DB::table('site_visits')->where('created_at', '>=', $from);
        $views = $q()->where('event', 'view');

        $byDay = $q()->selectRaw('day, event, count(*) as n, count(distinct visitor) as u')->groupBy('day', 'event')->get();
        $daily = [];
        for ($d = $from->copy(); $d->lte(now()); $d->addDay()) {
            $k = $d->toDateString();
            $rows = $byDay->filter(fn ($r) => substr((string) $r->day, 0, 10) === $k);
            $daily[] = [
                'day' => $k,
                'views' => (int) $rows->where('event', 'view')->sum('n'),
                'visitors' => (int) $rows->where('event', 'view')->sum('u'),
                'signups' => (int) $rows->where('event', 'signup')->sum('n'),
                'downloads' => (int) $rows->where('event', 'download')->sum('n'),
                'contacts' => (int) $rows->where('event', 'contact')->sum('n'),
            ];
        }
        $count = fn (string $e) => (int) $q()->where('event', $e)->count();
        $uniq = fn (string $e) => (int) $q()->where('event', $e)->distinct()->count('visitor');
        $top = fn (string $col, int $n = 8) => $q()->where('event', 'view')->whereNotNull($col)->selectRaw("$col as k, count(*) as n")->groupBy($col)->orderByDesc('n')->limit($n)->get()
            ->map(fn ($r) => ['label' => $r->k, 'count' => (int) $r->n])->values();
        $today = DB::table('site_visits')->where('day', now()->toDateString());
        $visitors = (int) (clone $views)->distinct()->count('visitor');
        $signups = $count('signup');

        $requests = Schema::hasTable('landing_requests') ? DB::table('landing_requests')->latest('id')->limit(100)->get() : collect();
        $recentSignups = Company::where('created_at', '>=', $from)->latest('id')->limit(30)->get(['id', 'name', 'email', 'phone', 'status', 'created_at'])
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'email' => $c->email, 'phone' => $c->phone, 'status' => $c->status, 'created_at' => $c->created_at?->toIso8601String(),
                'active_users' => User::where('company_id', $c->id)->whereNotNull('last_seen_at')->count()]);

        return [
            'days' => $days,
            'today' => ['views' => (int) (clone $today)->where('event', 'view')->count(), 'visitors' => (int) (clone $today)->where('event', 'view')->distinct()->count('visitor'),
                'signups' => (int) (clone $today)->where('event', 'signup')->count(), 'contacts' => (int) (clone $today)->where('event', 'contact')->count()],
            'totals' => ['views' => (int) (clone $views)->count(), 'visitors' => $visitors, 'signup_forms' => $uniq('signup_form'), 'signups' => $signups,
                'downloads' => $count('download'), 'contacts' => $count('contact'),
                'conversion' => $visitors > 0 ? round($signups * 100 / $visitors, 1) : 0.0],
            'funnel' => [
                ['label' => 'Visiteurs', 'value' => $visitors],
                ['label' => 'Ont ouvert l\'inscription', 'value' => $uniq('signup_form')],
                ['label' => 'Inscrits', 'value' => $signups],
                ['label' => 'Ont utilisé l\'ERP', 'value' => $recentSignups->where('active_users', '>', 0)->count()],
            ],
            'daily' => $daily,
            'sources' => $top('source'),
            'referrers' => $top('referrer'),
            'pages' => $top('path'),
            'campaigns' => $top('campaign'),
            'devices' => $top('device', 3),
            'os' => $top('os', 6),
            'downloads' => $q()->where('event', 'download')->selectRaw('detail as k, count(*) as n')->groupBy('detail')->get()->map(fn ($r) => ['label' => $r->k, 'count' => (int) $r->n])->values(),
            'requests' => $requests->map(fn ($r) => (array) $r + ['type_label' => \App\Http\Controllers\LandingController::REQUEST_TYPES[$r->type] ?? $r->type])->values(),
            'requests_new' => Schema::hasTable('landing_requests') ? DB::table('landing_requests')->where('status', 'new')->count() : 0,
            'signups_list' => $recentSignups->values(),
            'editor_company' => self::editorCompany()?->only(['id', 'name']),
        ];
    }
}
