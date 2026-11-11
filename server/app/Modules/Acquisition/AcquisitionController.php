<?php

namespace App\Modules\Acquisition;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Social\Networks;
use App\Support\Records\RecordController;
use App\Support\Records\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Publicité & acquisition : résultats, comptes publicitaires, Google Ads / Search Console, balises de suivi, essai de réception. */
class AcquisitionController extends Controller
{
    public const TRACKING = [
        'ga4' => ['Google Analytics 4 (G-…)', '/^G-[A-Z0-9]{4,15}$/'],
        'gtm' => ['Google Tag Manager (GTM-…)', '/^GTM-[A-Z0-9]{4,12}$/'],
        'google_ads' => ['Google Ads (AW-…)', '/^AW-\d{6,14}$/'],
        'google_ads_lead' => ['Google Ads : libellé de conversion « prospect »', '/^[A-Za-z0-9_-]{6,40}$/'],
        'google_ads_purchase' => ['Google Ads : libellé de conversion « achat »', '/^[A-Za-z0-9_-]{6,40}$/'],
        'meta_pixel' => ['Pixel Meta (Facebook / Instagram)', '/^\d{8,20}$/'],
        'tiktok_pixel' => ['Pixel TikTok', '/^[A-Z0-9]{10,30}$/'],
        'linkedin' => ['LinkedIn Insight (partner id)', '/^\d{4,12}$/'],
        'clarity' => ['Microsoft Clarity', '/^[a-z0-9]{6,20}$/'],
        'gsc' => ['Search Console : code de vérification (balise HTML)', '/^[A-Za-z0-9_-]{20,80}$/'],
        'meta_domain' => ['Meta : vérification du domaine', '/^[a-z0-9]{20,40}$/'],
    ];

    public function __construct(protected AdPlatforms $ads, protected Roi $roi) {}

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function report(Request $r)
    {
        $from = $r->query('from', now()->subDays(29)->toDateString());
        $to = $r->query('to', now()->toDateString());
        abort_unless(is_string($from) && is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && strtotime($from) && strtotime($to) && $from <= $to, 422, 'Période invalide.');
        abort_if(\Illuminate\Support\Carbon::parse($from)->diffInDays(\Illuminate\Support\Carbon::parse($to)) > 366, 422, 'Période trop longue : un an au maximum.');
        $c = app('tenant');
        $data = $this->roi->report($c, $from, $to);
        $data['recent'] = DB::table('ac_leads')->where('company_id', $c->id)->orderByDesc('received_at')->limit(8)->get(['id', 'title', 'platform', 'phone', 'status', 'received_at', 'campaign_id'])
            ->map(fn ($l) => (array) $l + ['campaign' => $l->campaign_id ? DB::table('ac_campaigns')->where('id', $l->campaign_id)->value('title') : null])->all();
        $data['setup'] = [
            'accounts' => DB::table('ac_accounts')->where('company_id', $c->id)->count(),
            'meta_pages' => DB::table('so_accounts')->where('company_id', $c->id)->where('network', 'facebook')->count(),
            'meta_leads_ready' => \App\Modules\Social\SocialOAuth::metaExtended() && DB::table('so_accounts')->where('company_id', $c->id)->where('network', 'facebook')->where('inbox_enabled', true)->exists(),
            'tracking' => count(LandingController::tracking($c)), 'pages' => DB::table('ac_pages')->where('company_id', $c->id)->where('status', 'published')->count(),
        ];

        return ['data' => $data];
    }

    // ------------------------------------------------------------------ comptes publicitaires

    protected function accountRow(object $a): array
    {
        $cr = AdPlatforms::creds($a);

        return ['id' => $a->id, 'platform' => $a->platform, 'platform_label' => AdPlatforms::CONNECT[$a->platform] ?? $a->platform, 'name' => $a->name, 'external_id' => $a->external_id,
            'currency' => $a->currency, 'pixel_id' => $a->pixel_id, 'send_conversions' => (bool) $a->send_conversions, 'has_capi_token' => ! empty($cr['capi_token']),
            'status' => $a->status, 'last_error' => $a->last_error, 'synced_at' => $a->synced_at,
            'campaigns' => DB::table('ac_campaigns')->where('account_id', $a->id)->count(),
            'events' => (object) DB::table('ac_events')->where('account_id', $a->id)->where('created_at', '>=', now()->subDays(30))->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all()];
    }

    public function accounts()
    {
        $app = Networks::app('google');

        return ['data' => DB::table('ac_accounts')->where('company_id', app('tenant')->id)->orderBy('platform')->get()->map(fn ($a) => $this->accountRow($a))->values(),
            'platforms' => AdPlatforms::CONNECT,
            'google' => ['available' => (bool) ($app['client_id'] && config('erp.ads.google_developer_token')), 'search_console' => (bool) $app['client_id'], 'redirect_uri' => AdPlatforms::googleRedirect(),
                'connected' => (bool) $this->googleCreds()]];
    }

    public function storeAccount(Request $r)
    {
        $v = $r->validate(['platform' => 'required|in:meta,tiktok,linkedin', 'external_id' => 'required|string|max:64', 'token' => 'required|string|max:2000',
            'pixel_id' => 'nullable|string|max:40', 'capi_token' => 'nullable|string|max:2000', 'send_conversions' => 'nullable|boolean']);

        return $this->guard(function () use ($v, $r) {
            $ext = trim(preg_replace('/^act_/', '', $v['external_id']));
            $info = $this->ads->check($v['platform'], $ext, ['token' => trim($v['token'])]);
            $id = DB::table('ac_accounts')->insertGetId(['company_id' => app('tenant')->id, 'platform' => $v['platform'], 'name' => Str::limit($info['name'] ?: AdPlatforms::CONNECT[$v['platform']], 150, ''),
                'external_id' => $info['external_id'], 'currency' => $info['currency'] ? strtoupper(substr($info['currency'], 0, 3)) : null, 'pixel_id' => $v['pixel_id'] ?? null,
                'send_conversions' => (bool) ($v['send_conversions'] ?? false), 'credentials' => AdPlatforms::encrypt(array_filter(['token' => trim($v['token']), 'capi_token' => isset($v['capi_token']) ? trim($v['capi_token']) : null])),
                'status' => 'active', 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            $acc = DB::table('ac_accounts')->find($id);
            rescue(fn () => $this->ads->sync(app('tenant'), $acc), null, false);

            return response()->json(['data' => $this->accountRow(DB::table('ac_accounts')->find($id))], 201);
        });
    }

    protected function account(int $id): object
    {
        return DB::table('ac_accounts')->where('company_id', app('tenant')->id)->where('id', $id)->first() ?? abort(404, 'Compte introuvable.');
    }

    public function updateAccount(Request $r, int $id)
    {
        $a = $this->account($id);
        $v = $r->validate(['pixel_id' => 'nullable|string|max:40', 'capi_token' => 'nullable|string|max:2000', 'send_conversions' => 'nullable|boolean', 'token' => 'nullable|string|max:2000', 'name' => 'nullable|string|max:150']);
        $cr = AdPlatforms::creds($a);
        foreach (['token', 'capi_token'] as $k) {
            if (! empty($v[$k])) {
                $cr[$k] = trim($v[$k]);
            }
        }
        if (! empty($v['send_conversions']) && $a->platform === 'meta' && empty($v['pixel_id'] ?? $a->pixel_id)) {
            abort(422, 'Indiquez l\'identifiant du pixel (ou jeu de données) Meta pour renvoyer les conversions.');
        }
        DB::table('ac_accounts')->where('id', $id)->update(array_filter(['pixel_id' => $v['pixel_id'] ?? null, 'name' => $v['name'] ?? null], fn ($x) => $x !== null)
            + (array_key_exists('send_conversions', $v) ? ['send_conversions' => (bool) $v['send_conversions']] : []) + ['credentials' => AdPlatforms::encrypt($cr), 'updated_at' => now()]);

        return ['data' => $this->accountRow($this->account($id))];
    }

    public function destroyAccount(int $id)
    {
        $this->account($id);
        DB::table('ac_campaigns')->where('account_id', $id)->update(['account_id' => null]);
        DB::table('ac_accounts')->where('id', $id)->delete();

        return response()->noContent();
    }

    public function syncAccount(int $id)
    {
        $a = $this->account($id);

        return $this->guard(function () use ($a, $id) {
            try {
                $n = $this->ads->sync(app('tenant'), $a, 90);
            } catch (\DomainException $e) {
                DB::table('ac_accounts')->where('id', $id)->update(['last_error' => Str::limit($e->getMessage(), 300, ''), 'status' => 'error', 'updated_at' => now()]);
                throw $e;
            }

            return ['data' => $this->accountRow($this->account($id)) + ['rows' => $n]];
        });
    }

    // ------------------------------------------------------------------ Google : Ads et Search Console

    protected function googleCreds(): ?array
    {
        $raw = app('tenant')->setting('acquisition_google');

        return $raw ? rescue(fn () => json_decode(decrypt($raw), true), null, false) : null;
    }

    public function googleConnect(Request $r)
    {
        $app = Networks::app('google');
        abort_unless($app['client_id'], 422, 'L\'application Google n\'est pas encore renseignée par l\'administrateur de la plateforme (Réseaux sociaux › Applications).');
        $state = Str::random(48);
        Cache::put('acq-google:'.$state, ['company' => app('tenant')->id, 'user' => $r->user()->id], now()->addMinutes(20));

        return ['url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => $app['client_id'], 'redirect_uri' => AdPlatforms::googleRedirect(), 'response_type' => 'code',
            'scope' => AdPlatforms::googleScopes(), 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state])];
    }

    public function googleCallback(Request $r)
    {
        $st = Cache::pull('acq-google:'.$r->query('state'));
        $done = fn (string $title, string $msg) => response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body style="font-family:system-ui;max-width:520px;margin:60px auto;padding:0 20px;line-height:1.5"><h2>'.e($title).'</h2><p>'.e($msg).'</p><p>Vous pouvez fermer cette page et revenir dans l\'application.</p></body>');
        if (! $st || ! $r->query('code')) {
            return $done('Connexion annulée', $r->query('error') ? 'Google a refusé la connexion ('.$r->query('error').').' : 'Lien expiré : recommencez depuis l\'application.');
        }
        $c = Company::find($st['company']);
        if (! $c) {
            return $done('Connexion impossible', 'Société introuvable.');
        }
        $app = Networks::app('google');
        $t = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'authorization_code', 'code' => $r->query('code'), 'client_id' => $app['client_id'],
            'client_secret' => $app['client_secret'], 'redirect_uri' => AdPlatforms::googleRedirect()]);
        if (! $t->ok() || ! $t->json('refresh_token')) {
            return $done('Connexion impossible', 'Google n\'a pas donné l\'accès hors connexion : réessayez en acceptant toutes les autorisations.');
        }
        $cr = ['refresh_token' => $t->json('refresh_token')];
        $s = (array) $c->settings;
        $s['acquisition_google'] = encrypt(json_encode($cr));
        $c->settings = $s;
        $c->save();
        app()->instance('tenant', $c);
        $added = 0;
        foreach (rescue(fn () => $this->ads->googleCustomers($cr), [], false) as $cust) {
            if (DB::table('ac_accounts')->where('company_id', $c->id)->where('platform', 'google')->where('external_id', $cust)->exists()) {
                continue;
            }
            $info = rescue(fn () => $this->ads->check('google', $cust, $cr), null, false);
            if (! $info) {
                continue; // compte administrateur ou sans accès
            }
            DB::table('ac_accounts')->insert(['company_id' => $c->id, 'platform' => 'google', 'name' => Str::limit($info['name'] ?: 'Google Ads '.$cust, 150, ''), 'external_id' => $cust,
                'currency' => $info['currency'], 'credentials' => AdPlatforms::encrypt($cr), 'status' => 'active', 'created_by' => $st['user'], 'created_at' => now(), 'updated_at' => now()]);
            $added++;
        }

        return $done('Google est relié', $added ? $added.' compte(s) Google Ads ajouté(s). Les dépenses arrivent dans quelques minutes.' : 'Search Console est disponible. Aucun compte Google Ads accessible n\'a été trouvé avec cette adresse.');
    }

    public function searchConsole(Request $r)
    {
        $cr = $this->googleCreds();
        if (! $cr) {
            return ['data' => ['connected' => false]];
        }

        return $this->guard(fn () => ['data' => ['connected' => true] + Cache::remember('gsc:'.app('tenant')->id.':'.md5((string) $r->query('site')), 3600,
            fn () => $this->ads->searchConsole($cr, $r->query('site') ?: null))]);
    }

    public function googleDisconnect()
    {
        $c = app('tenant');
        $s = (array) $c->settings;
        unset($s['acquisition_google']);
        $c->settings = $s;
        $c->save();
        DB::table('ac_accounts')->where('company_id', $c->id)->where('platform', 'google')->delete();

        return ['data' => ['ok' => true]];
    }

    // ------------------------------------------------------------------ balises de suivi

    public function tracking()
    {
        $t = (array) app('tenant')->setting('tracking', []);

        return ['data' => collect(self::TRACKING)->map(fn ($d, $k) => ['key' => $k, 'label' => $d[0], 'value' => $t[$k] ?? null])->values()];
    }

    public function saveTracking(Request $r)
    {
        $in = (array) $r->input('values', []);
        $out = [];
        foreach (self::TRACKING as $k => [$label, $re]) {
            $v = is_scalar($in[$k] ?? null) ? trim((string) $in[$k]) : '';
            if ($k === 'gsc' && preg_match('/content="([^"]+)"/', $v, $m)) {
                $v = $m[1]; // balise HTML collée entière
            }
            if ($v === '') {
                continue;
            }
            abort_unless(preg_match($re, $v), 422, $label.' : format incorrect ('.$v.').');
            $out[$k] = $v;
        }
        $c = app('tenant');
        $s = (array) $c->settings;
        $s['tracking'] = $out;
        $c->settings = $s;
        $c->save();

        return $this->tracking();
    }

    /** Envoie un prospect d'essai dans une source pour vérifier tout le circuit (commercial, réponse, relance). */
    public function testLead(Request $r, int $source)
    {
        $s = DB::table('ac_sources')->where('company_id', app('tenant')->id)->where('id', $source)->first() ?? abort(404);
        $u = $r->user();

        return $this->guard(function () use ($s, $u) {
            $res = app(LeadIntake::class)->receive(app('tenant'), $s, $s->platform === 'meta' ? 'meta' : ($s->platform === 'landing' ? 'landing' : $s->platform),
                ['full_name' => 'Prospect d\'essai', 'phone_number' => $u->phone ?: '+2250700000000', 'email' => $u->email], ['external_id' => 'test-'.Str::random(10), 'form_name' => 'Essai depuis Yomara', 'campaign_name' => 'Essai']);

            return ['data' => $res];
        });
    }

    public function out(string $res, array $row): array
    {
        return app(RecordController::class)->present(Records::resource(__DIR__.'/records.php', $res), [$row])[0];
    }
}
