<?php

namespace App\Modules\Acquisition;

use App\Models\Company;
use App\Modules\Social\Networks;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Comptes publicitaires : relevé des dépenses par campagne et par jour (Meta, Google Ads, TikTok, LinkedIn),
 * lecture des prospects Meta, et conversions renvoyées à Meta (API Conversions).
 */
class AdPlatforms
{
    public const CONNECT = ['meta' => 'Meta (Facebook, Instagram)', 'google' => 'Google Ads', 'tiktok' => 'TikTok Ads', 'linkedin' => 'LinkedIn Ads'];

    public static function graph(): string
    {
        return 'https://graph.facebook.com/'.config('erp.ads.meta_version', 'v21.0');
    }

    public static function creds(object $acc): array
    {
        return $acc->credentials ? (json_decode(Crypt::decryptString($acc->credentials), true) ?: []) : [];
    }

    public static function encrypt(array $creds): string
    {
        return Crypt::encryptString(json_encode($creds));
    }

    protected function fail(string $who, $res): never
    {
        $msg = $res->json('error.message') ?? $res->json('message') ?? $res->json('error.errors.0.message') ?? $res->json('0.error.message') ?? Str::limit((string) $res->body(), 160);
        throw new \DomainException($who.' : '.(is_string($msg) ? $msg : 'erreur '.$res->status()));
    }

    // ------------------------------------------------------------------ vérification à la connexion

    /** Vérifie les identifiants et renvoie le nom et la devise du compte. */
    public function check(string $platform, string $externalId, array $cr): array
    {
        switch ($platform) {
            case 'meta':
                $id = 'act_'.preg_replace('/^act_/', '', $externalId);
                $r = Http::timeout(20)->get(self::graph().'/'.$id, ['fields' => 'name,currency,account_status', 'access_token' => $cr['token'] ?? '']);
                $r->ok() || $this->fail('Meta', $r);

                return ['name' => $r->json('name'), 'currency' => $r->json('currency'), 'external_id' => preg_replace('/^act_/', '', $externalId)];
            case 'tiktok':
                $r = Http::timeout(20)->withHeaders(['Access-Token' => $cr['token'] ?? ''])->get('https://business-api.tiktok.com/open_api/v1.3/advertiser/info/', ['advertiser_ids' => json_encode([$externalId])]);
                ($r->ok() && (int) $r->json('code') === 0) || $this->fail('TikTok', $r);

                return ['name' => $r->json('data.list.0.name'), 'currency' => $r->json('data.list.0.currency'), 'external_id' => $externalId];
            case 'linkedin':
                $r = Http::timeout(20)->withToken($cr['token'] ?? '')->withHeaders(['LinkedIn-Version' => '202501', 'X-Restli-Protocol-Version' => '2.0.0'])->get('https://api.linkedin.com/rest/adAccounts/'.$externalId);
                $r->ok() || $this->fail('LinkedIn', $r);

                return ['name' => $r->json('name'), 'currency' => $r->json('currency'), 'external_id' => $externalId];
            case 'google':
                $rows = $this->googleQuery($externalId, $cr, 'SELECT customer.descriptive_name, customer.currency_code FROM customer LIMIT 1');

                return ['name' => $rows[0]['customer']['descriptiveName'] ?? null, 'currency' => $rows[0]['customer']['currencyCode'] ?? null, 'external_id' => $externalId];
        }
        throw new \DomainException('Plateforme non prise en charge.');
    }

    // ------------------------------------------------------------------ dépenses

    /** Relève les dépenses des derniers jours et met à jour campagnes et dépenses. Renvoie le nombre de lignes. */
    public function sync(Company $c, object $acc, int $days = 30): int
    {
        $from = now()->subDays($days - 1)->toDateString();
        $to = now()->toDateString();
        $cr = self::creds($acc);
        $rows = match ($acc->platform) {
            'meta' => $this->metaInsights($acc, $cr, $from, $to),
            'google' => $this->googleInsights($acc, $cr, $from, $to),
            'tiktok' => $this->tiktokInsights($acc, $cr, $from, $to),
            'linkedin' => $this->linkedinInsights($acc, $cr, $from, $to),
            default => [],
        };
        $n = 0;
        $intake = app(LeadIntake::class);
        foreach ($rows as $r) {
            $campId = $intake->campaign($c, $acc->platform, (string) $r['campaign_id'], $r['campaign_name'] ?? null, null);
            DB::table('ac_campaigns')->where('id', $campId)->update(array_filter(['account_id' => $acc->id, 'title' => $r['campaign_name'] ? Str::limit($r['campaign_name'], 200, '') : null, 'updated_at' => now()]));
            DB::table('ac_spend')->updateOrInsert(['campaign_id' => $campId, 'date' => $r['date'], 'source' => 'api'], [
                'company_id' => $c->id, 'amount' => round((float) $r['spend'], 2), 'impressions' => (int) ($r['impressions'] ?? 0), 'clicks' => (int) ($r['clicks'] ?? 0),
                'platform_leads' => isset($r['leads']) ? (int) $r['leads'] : null, 'platform_conversions' => isset($r['conversions']) ? (float) $r['conversions'] : null, 'updated_at' => now(), 'created_at' => now(),
            ]);
            $n++;
        }
        DB::table('ac_accounts')->where('id', $acc->id)->update(['synced_at' => now(), 'last_error' => null, 'status' => 'active', 'updated_at' => now()]);

        return $n;
    }

    protected function metaInsights(object $acc, array $cr, string $from, string $to): array
    {
        $out = [];
        $url = self::graph().'/act_'.$acc->external_id.'/insights';
        $params = ['level' => 'campaign', 'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,actions', 'time_increment' => 1,
            'time_range' => json_encode(['since' => $from, 'until' => $to]), 'limit' => 500, 'access_token' => $cr['token'] ?? ''];
        for ($page = 0; $url && $page < 20; $page++) {
            $r = Http::timeout(40)->get($url, $params);
            $r->ok() || $this->fail('Meta', $r);
            foreach ((array) $r->json('data') as $d) {
                $leads = 0;
                foreach ((array) ($d['actions'] ?? []) as $a) {
                    if (in_array($a['action_type'] ?? '', ['lead', 'onsite_conversion.lead_grouped', 'leadgen_grouped'], true)) {
                        $leads = max($leads, (int) $a['value']);
                    }
                }
                $out[] = ['campaign_id' => $d['campaign_id'], 'campaign_name' => $d['campaign_name'] ?? null, 'date' => $d['date_start'], 'spend' => $d['spend'] ?? 0,
                    'impressions' => $d['impressions'] ?? 0, 'clicks' => $d['clicks'] ?? 0, 'leads' => $leads];
            }
            $url = $r->json('paging.next');
            $params = [];
        }

        return $out;
    }

    protected function tiktokInsights(object $acc, array $cr, string $from, string $to): array
    {
        $r = Http::timeout(40)->withHeaders(['Access-Token' => $cr['token'] ?? ''])->get('https://business-api.tiktok.com/open_api/v1.3/report/integrated/get/', [
            'advertiser_id' => $acc->external_id, 'report_type' => 'BASIC', 'data_level' => 'AUCTION_CAMPAIGN', 'dimensions' => json_encode(['campaign_id', 'stat_time_day']),
            'metrics' => json_encode(['campaign_name', 'spend', 'impressions', 'clicks', 'conversion']), 'start_date' => $from, 'end_date' => $to, 'page_size' => 1000,
        ]);
        ($r->ok() && (int) $r->json('code') === 0) || $this->fail('TikTok', $r);

        return array_map(fn ($d) => ['campaign_id' => $d['dimensions']['campaign_id'], 'campaign_name' => $d['metrics']['campaign_name'] ?? null, 'date' => substr((string) $d['dimensions']['stat_time_day'], 0, 10),
            'spend' => $d['metrics']['spend'] ?? 0, 'impressions' => $d['metrics']['impressions'] ?? 0, 'clicks' => $d['metrics']['clicks'] ?? 0, 'conversions' => $d['metrics']['conversion'] ?? null], (array) $r->json('data.list'));
    }

    protected function linkedinInsights(object $acc, array $cr, string $from, string $to): array
    {
        $f = \Illuminate\Support\Carbon::parse($from);
        $t = \Illuminate\Support\Carbon::parse($to);
        $range = '(start:(year:'.$f->year.',month:'.$f->month.',day:'.$f->day.'),end:(year:'.$t->year.',month:'.$t->month.',day:'.$t->day.'))';
        $http = Http::timeout(40)->withToken($cr['token'] ?? '')->withHeaders(['LinkedIn-Version' => '202501', 'X-Restli-Protocol-Version' => '2.0.0']);
        $r = $http->get('https://api.linkedin.com/rest/adAnalytics?q=analytics&pivot=CAMPAIGN&timeGranularity=DAILY&dateRange='.$range
            .'&accounts=List(urn%3Ali%3AsponsoredAccount%3A'.rawurlencode((string) $acc->external_id).')&fields=costInLocalCurrency,impressions,clicks,oneClickLeads,pivotValues,dateRange');
        $r->ok() || $this->fail('LinkedIn', $r);
        $names = [];
        $c = $http->get('https://api.linkedin.com/rest/adAccounts/'.$acc->external_id.'/adCampaigns', ['q' => 'search', 'pageSize' => 100]);
        foreach ((array) ($c->ok() ? $c->json('elements') : []) as $e) {
            $names[(string) $e['id']] = $e['name'] ?? null;
        }
        $out = [];
        foreach ((array) $r->json('elements') as $e) {
            $id = preg_replace('/^.*:/', '', (string) ($e['pivotValues'][0] ?? ''));
            $d = $e['dateRange']['start'] ?? null;
            if (! $id || ! $d) {
                continue;
            }
            $out[] = ['campaign_id' => $id, 'campaign_name' => $names[$id] ?? null, 'date' => sprintf('%04d-%02d-%02d', $d['year'], $d['month'], $d['day']),
                'spend' => $e['costInLocalCurrency'] ?? 0, 'impressions' => $e['impressions'] ?? 0, 'clicks' => $e['clicks'] ?? 0, 'leads' => $e['oneClickLeads'] ?? null];
        }

        return $out;
    }

    // ------------------------------------------------------------------ Google (Ads et Search Console)

    public static function googleRedirect(): string
    {
        return url('/oauth/acquisition/google/retour');
    }

    public static function googleScopes(): string
    {
        return 'openid email https://www.googleapis.com/auth/adwords https://www.googleapis.com/auth/webmasters.readonly';
    }

    public function googleAccessToken(array $cr): string
    {
        $app = Networks::app('google');
        if (! $app['client_id'] || ! $app['client_secret']) {
            throw new \DomainException('L\'application Google n\'est pas encore renseignée par l\'administrateur de la plateforme.');
        }
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', ['grant_type' => 'refresh_token', 'refresh_token' => $cr['refresh_token'] ?? '',
            'client_id' => $app['client_id'], 'client_secret' => $app['client_secret']]);
        $r->ok() || $this->fail('Google', $r);

        return (string) $r->json('access_token');
    }

    public function googleQuery(string $customerId, array $cr, string $gaql): array
    {
        $dev = config('erp.ads.google_developer_token');
        if (! $dev) {
            throw new \DomainException('Google Ads n\'est pas encore activé sur la plateforme (jeton développeur manquant dans la configuration du serveur).');
        }
        $headers = ['developer-token' => $dev];
        if ($login = config('erp.ads.google_login_customer_id')) {
            $headers['login-customer-id'] = preg_replace('/\D/', '', $login);
        }
        $r = Http::timeout(60)->withToken($this->googleAccessToken($cr))->withHeaders($headers)
            ->post('https://googleads.googleapis.com/'.config('erp.ads.google_version', 'v21').'/customers/'.preg_replace('/\D/', '', $customerId).'/googleAds:searchStream', ['query' => $gaql]);
        $r->ok() || $this->fail('Google Ads', $r);
        $rows = [];
        foreach ((array) $r->json() as $batch) {
            foreach ((array) ($batch['results'] ?? []) as $row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    protected function googleInsights(object $acc, array $cr, string $from, string $to): array
    {
        $rows = $this->googleQuery($acc->external_id, $cr, "SELECT campaign.id, campaign.name, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions FROM campaign WHERE segments.date BETWEEN '$from' AND '$to'");

        return array_map(fn ($r) => ['campaign_id' => (string) $r['campaign']['id'], 'campaign_name' => $r['campaign']['name'] ?? null, 'date' => $r['segments']['date'],
            'spend' => ((int) ($r['metrics']['costMicros'] ?? 0)) / 1_000_000, 'impressions' => $r['metrics']['impressions'] ?? 0, 'clicks' => $r['metrics']['clicks'] ?? 0,
            'conversions' => $r['metrics']['conversions'] ?? null], $rows);
    }

    /** Comptes Google Ads accessibles après la connexion. */
    public function googleCustomers(array $cr): array
    {
        $dev = config('erp.ads.google_developer_token');
        if (! $dev) {
            return [];
        }
        $r = Http::timeout(20)->withToken($this->googleAccessToken($cr))->withHeaders(['developer-token' => $dev])
            ->get('https://googleads.googleapis.com/'.config('erp.ads.google_version', 'v21').'/customers:listAccessibleCustomers');

        return $r->ok() ? array_map(fn ($n) => preg_replace('/^customers\//', '', $n), (array) $r->json('resourceNames')) : [];
    }

    /** Search Console : requêtes et pages qui amènent des visiteurs depuis Google (28 derniers jours). */
    public function searchConsole(array $cr, ?string $site = null): array
    {
        $tok = $this->googleAccessToken($cr);
        $sites = Http::timeout(20)->withToken($tok)->get('https://www.googleapis.com/webmasters/v3/sites');
        $sites->ok() || $this->fail('Search Console', $sites);
        $list = array_values(array_map(fn ($s) => $s['siteUrl'], array_filter((array) $sites->json('siteEntry'), fn ($s) => ($s['permissionLevel'] ?? '') !== 'siteUnverifiedUser')));
        $site ??= $list[0] ?? null;
        if (! $site) {
            return ['sites' => [], 'site' => null];
        }
        $body = fn (string $dim) => ['startDate' => now()->subDays(30)->toDateString(), 'endDate' => now()->subDays(2)->toDateString(), 'dimensions' => [$dim], 'rowLimit' => 25];
        $base = 'https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($site).'/searchAnalytics/query';
        $q = Http::timeout(30)->withToken($tok)->post($base, $body('query'));
        $q->ok() || $this->fail('Search Console', $q);
        $p = Http::timeout(30)->withToken($tok)->post($base, $body('page'));
        $d = Http::timeout(30)->withToken($tok)->post($base, $body('date') + ['rowLimit' => 40]);
        $map = fn ($rows) => array_map(fn ($r) => ['key' => $r['keys'][0], 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'ctr' => round($r['ctr'] * 100, 1), 'position' => round($r['position'], 1)], (array) $rows);
        $days = $map($d->ok() ? $d->json('rows') : []);

        return ['sites' => $list, 'site' => $site, 'queries' => $map($q->json('rows')), 'pages' => $map($p->ok() ? $p->json('rows') : []), 'daily' => $days,
            'clicks' => array_sum(array_column($days, 'clicks')), 'impressions' => array_sum(array_column($days, 'impressions'))];
    }

    // ------------------------------------------------------------------ Meta : prospects et conversions

    /** Lit un prospect Meta à partir de son identifiant (jeton de la page). */
    public function metaLead(string $leadgenId, string $pageToken): array
    {
        $r = Http::timeout(20)->get(self::graph().'/'.$leadgenId, ['fields' => 'created_time,field_data,ad_id,ad_name,adset_name,campaign_id,campaign_name,form_id,platform', 'access_token' => $pageToken]);
        $r->ok() || $this->fail('Meta', $r);
        $answers = [];
        foreach ((array) $r->json('field_data') as $f) {
            $answers[(string) $f['name']] = implode(', ', (array) ($f['values'] ?? []));
        }
        $form = $r->json('form_id') ? Http::timeout(15)->get(self::graph().'/'.$r->json('form_id'), ['fields' => 'name', 'access_token' => $pageToken]) : null;

        return ['answers' => $answers, 'meta' => ['external_id' => $leadgenId, 'campaign_id' => $r->json('campaign_id'), 'campaign_name' => $r->json('campaign_name'), 'ad_name' => $r->json('ad_name'),
            'form_name' => $form?->ok() ? $form->json('name') : null, 'form_id' => $r->json('form_id'), 'utm_source' => $r->json('platform') === 'ig' ? 'instagram' : 'facebook']];
    }

    protected static function hash(?string $v, bool $phone = false): ?string
    {
        $v = $phone ? preg_replace('/\D/', '', (string) $v) : mb_strtolower(trim((string) $v));

        return $v ? hash('sha256', $v) : null;
    }

    /**
     * Conversion renvoyée à Meta (API Conversions) : la publicité apprend quels prospects deviennent clients.
     * $user : email, phone, lead_id (prospect Meta), fbc, fbp, ip, ua ; $source : system_generated ou website.
     */
    public function metaConversion(Company $c, string $event, array $user, ?float $value, string $ref, string $source = 'system_generated', ?string $url = null): ?array
    {
        $acc = DB::table('ac_accounts')->where('company_id', $c->id)->where('platform', 'meta')->where('send_conversions', true)->whereNotNull('pixel_id')->first();
        if (! $acc || DB::table('ac_events')->where('company_id', $c->id)->where('ref', $ref)->where('event', $event)->where('status', 'ok')->exists()) {
            return null;
        }
        $cr = self::creds($acc);
        $token = $cr['capi_token'] ?? $cr['token'] ?? null;
        if (! $token) {
            return null;
        }
        $ud = array_filter(['em' => ($e = self::hash($user['email'] ?? null)) ? [$e] : null, 'ph' => ($p = self::hash($user['phone'] ?? null, true)) ? [$p] : null,
            'lead_id' => $user['lead_id'] ?? null, 'fbc' => $user['fbc'] ?? null, 'fbp' => $user['fbp'] ?? null, 'client_ip_address' => $user['ip'] ?? null, 'client_user_agent' => $user['ua'] ?? null]);
        $ev = array_filter(['event_name' => $event, 'event_time' => time(), 'action_source' => $source, 'event_id' => $ref, 'event_source_url' => $url, 'user_data' => $ud,
            'custom_data' => $value !== null ? ['value' => round($value, 2), 'currency' => strtoupper($acc->currency ?: ($c->currency ?: 'XOF'))] : null]);
        try {
            $r = Http::timeout(20)->post(self::graph().'/'.$acc->pixel_id.'/events', ['data' => [$ev], 'access_token' => $token]);
            $ok = $r->ok();
            $msg = $ok ? 'reçu par Meta ('.(int) $r->json('events_received').')' : (string) ($r->json('error.message') ?? $r->status());
        } catch (\Throwable $e) {
            $ok = false;
            $msg = $e->getMessage();
        }
        DB::table('ac_events')->insert(['company_id' => $c->id, 'account_id' => $acc->id, 'platform' => 'meta', 'event' => $event, 'ref' => Str::limit($ref, 120, ''), 'value' => $value,
            'status' => $ok ? 'ok' : 'error', 'response' => Str::limit($msg, 300, ''), 'created_at' => now(), 'updated_at' => now()]);

        return ['ok' => $ok, 'message' => $msg];
    }
}
