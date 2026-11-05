<?php

namespace App\Modules\Liens;

use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Liens courts, smartlinks et QR codes dynamiques : création, redirection, comptage des clics et statistiques. */
class LinkService
{
    /** Plateformes proposées sur une page smartlink (libellé, couleur). */
    public const PLATFORMS = [
        'spotify' => ['Spotify', '#1DB954'], 'apple_music' => ['Apple Music', '#FA243C'], 'youtube' => ['YouTube', '#FF0000'], 'youtube_music' => ['YouTube Music', '#FF0000'],
        'deezer' => ['Deezer', '#A238FF'], 'audiomack' => ['Audiomack', '#FFA200'], 'boomplay' => ['Boomplay', '#0B5CFF'], 'soundcloud' => ['SoundCloud', '#FF5500'],
        'tidal' => ['Tidal', '#000000'], 'amazon_music' => ['Amazon Music', '#25D1DA'], 'tiktok' => ['TikTok', '#000000'], 'instagram' => ['Instagram', '#E1306C'],
        'facebook' => ['Facebook', '#1877F2'], 'x' => ['X', '#000000'], 'whatsapp' => ['WhatsApp', '#25D366'], 'telegram' => ['Telegram', '#229ED9'],
        'snapchat' => ['Snapchat', '#FFFC00'], 'linkedin' => ['LinkedIn', '#0A66C2'], 'sensya' => ['Sensya', '#6C3CE1'], 'app_store' => ['App Store', '#0D96F6'],
        'google_play' => ['Google Play', '#01875F'], 'website' => ['Site web', '#0A1633'], 'shop' => ['Boutique', '#F5A000'], 'other' => ['Lien', '#64748B'],
    ];

    public const RESERVED = ['admin', 'api', 'app', 'login', 'inscription', 'contact', 'modules', 'tarifs', 'qr', 'stats', 'yomara'];

    protected function fail(string $m): never
    {
        throw new \DomainException($m);
    }

    public static function publicUrl(string $slug): string
    {
        $d = self::shortDomain();

        return $d ? 'https://'.$d.'/'.$slug : url('/l/'.$slug);
    }

    /** Domaine court des liens (sans https://), ou null. */
    public static function shortDomain(): ?string
    {
        $d = preg_replace('#^https?://#', '', (string) config('erp.links_domain'));
        $d = strtolower(trim((string) $d, " /"));

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $d) ? $d : null;
    }

    /** Détection de la plateforme d'après l'adresse (pour proposer le bon bouton). */
    public static function guessPlatform(string $url): string
    {
        $h = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $map = ['open.spotify' => 'spotify', 'music.apple' => 'apple_music', 'music.youtube' => 'youtube_music', 'youtu' => 'youtube', 'deezer' => 'deezer', 'audiomack' => 'audiomack',
            'boomplay' => 'boomplay', 'soundcloud' => 'soundcloud', 'tidal' => 'tidal', 'music.amazon' => 'amazon_music', 'tiktok' => 'tiktok', 'instagram' => 'instagram',
            'facebook' => 'facebook', 'fb.' => 'facebook', 'x.com' => 'x', 'twitter' => 'x', 'wa.me' => 'whatsapp', 'whatsapp' => 'whatsapp', 't.me' => 'telegram',
            'snapchat' => 'snapchat', 'linkedin' => 'linkedin', 'sensya' => 'sensya', 'apps.apple' => 'app_store', 'play.google' => 'google_play'];
        foreach ($map as $k => $p) {
            if (str_contains($h, $k)) {
                return $p;
            }
        }

        return 'website';
    }

    protected function cleanUrl(?string $u, bool $required = false): ?string
    {
        $u = trim((string) $u);
        if ($u === '') {
            $required && $this->fail('Indiquez l\'adresse de destination.');

            return null;
        }
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $u)) {
            $u = 'https://'.$u;
        }
        $parts = parse_url($u);
        if (! $parts || ! in_array(mb_strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || ! str_contains($parts['host'], '.')) {
            $this->fail('Adresse invalide : '.$u);
        }
        // caractères qui permettraient de sortir d'un attribut ou d'une règle CSS (image de couverture…) : encodés
        $u = strtr($u, [' ' => '%20', '"' => '%22', "'" => '%27', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', '\\' => '%5C']);
        // Pas de lien vers soi-même (boucle) ni vers un hôte interne
        $self = parse_url(url('/'), PHP_URL_HOST);
        if ((mb_strtolower($parts['host']) === mb_strtolower((string) $self) && str_starts_with((string) ($parts['path'] ?? ''), '/l/')) || mb_strtolower($parts['host']) === self::shortDomain()) {
            $this->fail('Un lien ne peut pas renvoyer vers un autre lien court.');
        }
        if (filter_var($parts['host'], FILTER_VALIDATE_IP) && ! filter_var($parts['host'], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            $this->fail('Adresse interne non autorisée.');
        }

        return mb_substr($u, 0, 2000);
    }

    protected function slug(?string $wanted, ?int $ignoreId = null): string
    {
        if ($wanted !== null && trim($wanted) !== '') {
            $s = trim(preg_replace('/[^a-z0-9-]+/', '-', Str::lower(Str::ascii($wanted))), '-');
            (mb_strlen($s) >= 3 && mb_strlen($s) <= 60) || $this->fail('L\'alias doit faire de 3 à 60 caractères (lettres, chiffres, tirets).');
            in_array($s, self::RESERVED, true) && $this->fail('Cet alias est réservé.');
            DB::table('lk_links')->where('slug', $s)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists() && $this->fail('L\'alias « '.$s.' » est déjà pris.');

            return $s;
        }
        do {
            $s = Str::lower(Str::random(5));
        } while (DB::table('lk_links')->where('slug', $s)->exists() || in_array($s, self::RESERVED, true));

        return $s;
    }

    public function save(Company $c, User $u, array $d, ?int $id = null): array
    {
        $row = $id ? (DB::table('lk_links')->where('company_id', $c->id)->where('id', $id)->first() ?: $this->fail('Lien introuvable.')) : null;
        $kind = $d['kind'] ?? $row?->kind ?? 'short';
        in_array($kind, ['short', 'smart', 'page'], true) || $this->fail('Type de lien inconnu.');
        $target = $this->cleanUrl($d['target_url'] ?? null, $kind !== 'page');
        $rules = null;
        if ($kind === 'smart') {
            $dev = [];
            foreach (['ios', 'android', 'desktop'] as $k) {
                if ($v = $this->cleanUrl($d['rules']['devices'][$k] ?? null)) {
                    $dev[$k] = $v;
                }
            }
            $countries = [];
            foreach ((array) ($d['rules']['countries'] ?? []) as $cc => $v) {
                if (preg_match('/^[A-Z]{2}$/', (string) $cc) && ($v = $this->cleanUrl($v))) {
                    $countries[$cc] = $v;
                }
            }
            $rules = ['devices' => (object) $dev, 'countries' => (object) $countries];
        }
        // masquage (page affichée sous votre lien) : pas pendant l'essai gratuit, pour éviter les faux sites
        if (! empty($d['cloak']) && $c->status === 'trial') {
            $this->fail('Le masquage de lien est disponible une fois votre formule choisie (pas pendant l\'essai gratuit).');
        }
        $page = null;
        if ($kind === 'page') {
            $buttons = [];
            foreach (array_slice((array) ($d['page']['buttons'] ?? []), 0, 30) as $b) {
                if (! is_array($b) || ! ($url = $this->cleanUrl($b['url'] ?? null))) {
                    continue;
                }
                $p = isset(self::PLATFORMS[$b['platform'] ?? '']) ? $b['platform'] : self::guessPlatform($url);
                $buttons[] = ['label' => mb_substr(trim((string) ($b['label'] ?? '')) ?: self::PLATFORMS[$p][0], 0, 60), 'url' => $url, 'platform' => $p,
                    'action' => mb_substr((string) ($b['action'] ?? ''), 0, 30) ?: null];
            }
            $buttons || $this->fail('Ajoutez au moins un bouton (plateforme et adresse) à la page.');
            $page = ['subtitle' => mb_substr((string) ($d['page']['subtitle'] ?? ''), 0, 160), 'description' => mb_substr((string) ($d['page']['description'] ?? ''), 0, 600),
                'cover_url' => $this->cleanUrl($d['page']['cover_url'] ?? null), 'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) ($d['page']['color'] ?? '')) ? $d['page']['color'] : '#0A1633',
                'buttons' => $buttons];
        }
        $utm = array_filter(array_intersect_key((array) ($d['utm'] ?? []), array_flip(['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'])), fn ($v) => is_string($v) && trim($v) !== '');
        $set = [
            'kind' => $kind, 'title' => mb_substr(trim((string) ($d['title'] ?? '')), 0, 190) ?: null, 'target_url' => $target,
            'rules' => $rules ? json_encode($rules) : null, 'page' => $page ? json_encode($page) : null, 'utm' => $utm ? json_encode($utm) : null,
            'cloak' => (bool) ($d['cloak'] ?? false), 'expires_at' => ! empty($d['expires_at']) ? \Illuminate\Support\Carbon::parse($d['expires_at']) : null,
            'max_clicks' => ! empty($d['max_clicks']) ? max(1, (int) $d['max_clicks']) : null, 'tags' => json_encode(array_values(array_slice(array_filter((array) ($d['tags'] ?? []), 'is_string'), 0, 10))),
            'active' => (bool) ($d['active'] ?? true), 'updated_at' => now(),
        ];
        if (array_key_exists('password', $d)) {
            $set['password'] = $d['password'] ? Hash::make((string) $d['password']) : null;
        }
        if (! $row || (isset($d['slug']) && $d['slug'] !== $row->slug)) {
            $set['slug'] = $this->slug($d['slug'] ?? null, $row?->id);
        }
        if ($row) {
            DB::table('lk_links')->where('id', $row->id)->update($set);
            $id = $row->id;
        } else {
            $id = DB::table('lk_links')->insertGetId($set + ['company_id' => $c->id, 'user_id' => $u->id, 'created_at' => now()]);
        }

        return $this->show($c, $id);
    }

    public function row(object $l): array
    {
        return [
            'id' => $l->id, 'kind' => $l->kind, 'slug' => $l->slug, 'short_url' => self::publicUrl($l->slug), 'title' => $l->title, 'target_url' => $l->target_url,
            'rules' => json_decode($l->rules ?? 'null', true), 'page' => json_decode($l->page ?? 'null', true), 'utm' => json_decode($l->utm ?? 'null', true) ?: (object) [],
            'has_password' => (bool) $l->password, 'cloak' => (bool) $l->cloak, 'expires_at' => $l->expires_at, 'max_clicks' => $l->max_clicks,
            'tags' => json_decode($l->tags ?? '[]', true) ?: [], 'active' => (bool) $l->active, 'clicks' => (int) $l->clicks_count, 'uniques' => (int) $l->unique_count,
            'last_click_at' => $l->last_click_at, 'created_at' => $l->created_at, 'qr_png' => url('/l/'.$l->slug.'/qr.png'),
        ];
    }

    public function show(Company $c, int $id): array
    {
        $l = DB::table('lk_links')->where('company_id', $c->id)->where('id', $id)->first() ?: $this->fail('Lien introuvable.');

        return $this->row($l);
    }

    // ------------------------------------------------------------------ visite d'un lien

    public static function isBot(string $ua): bool
    {
        return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|whatsapp|telegrambot|twitterbot|linkedinbot|discordbot|preview|headless|curl|wget|python|scrapy|httpclient/i', $ua);
    }

    public static function device(string $ua): array
    {
        $os = match (true) {
            (bool) preg_match('/iphone|ipad|ipod/i', $ua) => 'iOS',
            (bool) preg_match('/android/i', $ua) => 'Android',
            (bool) preg_match('/windows/i', $ua) => 'Windows',
            (bool) preg_match('/mac os|macintosh/i', $ua) => 'macOS',
            (bool) preg_match('/linux/i', $ua) => 'Linux',
            default => 'Autre',
        };
        $device = preg_match('/ipad|tablet/i', $ua) ? 'tablet' : (preg_match('/mobi|iphone|android/i', $ua) ? 'mobile' : 'desktop');
        $browser = match (true) {
            (bool) preg_match('/FBAN|FBAV|Instagram/i', $ua) => 'Facebook / Instagram',
            (bool) preg_match('/edg\//i', $ua) => 'Edge',
            (bool) preg_match('/opr\/|opera/i', $ua) => 'Opera',
            (bool) preg_match('/chrome|crios/i', $ua) => 'Chrome',
            (bool) preg_match('/firefox|fxios/i', $ua) => 'Firefox',
            (bool) preg_match('/safari/i', $ua) => 'Safari',
            default => 'Autre',
        };

        return [$device, $os, $browser];
    }

    /** Adresse finale selon l'appareil et le pays du visiteur (smartlink), avec les paramètres UTM. */
    public static function destination(object $l, Request $r, ?string $country): ?string
    {
        $url = $l->target_url;
        if ($l->kind === 'smart') {
            $rules = json_decode($l->rules ?? '{}', true) ?: [];
            [, $os] = self::device((string) $r->userAgent());
            $dev = $rules['devices'] ?? [];
            $byOs = ['iOS' => 'ios', 'Android' => 'android'][$os] ?? 'desktop';
            $url = ($country && ! empty($rules['countries'][$country]) ? $rules['countries'][$country] : null) ?? ($dev[$byOs] ?? null) ?? $url;
        }
        $utm = json_decode($l->utm ?? '[]', true) ?: [];
        if ($url && $utm) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($utm);
        }

        return $url;
    }

    public static function country(Request $r): ?string
    {
        // Derrière Cloudflare : pays du visiteur fourni par l'en-tête ; sinon région de la langue du navigateur
        $cc = strtoupper((string) ($r->header('CF-IPCountry') ?: $r->header('X-Country-Code')));
        if (preg_match('/^[A-Z]{2}$/', $cc) && ! in_array($cc, ['XX', 'T1'], true)) {
            return $cc;
        }
        if (preg_match('/^[a-z]{2}-([A-Z]{2})/', (string) $r->header('Accept-Language'), $m)) {
            return $m[1];
        }

        return null;
    }

    /** Enregistre un clic (pas les robots ni les aperçus de lien des messageries). */
    public static function track(object $l, Request $r, string $source = 'link', ?int $button = null): void
    {
        $ua = (string) $r->userAgent();
        if (self::isBot($ua)) {
            return;
        }
        $visitor = sha1($l->id.'|'.$r->ip().'|'.$ua.'|'.config('app.key'));
        $unique = ! DB::table('lk_clicks')->where('link_id', $l->id)->where('visitor', $visitor)->exists();
        [$device, $os, $browser] = self::device($ua);
        $ref = parse_url((string) $r->headers->get('referer'), PHP_URL_HOST);
        DB::table('lk_clicks')->insert(['link_id' => $l->id, 'company_id' => $l->company_id, 'source' => $source === 'qr' ? 'qr' : 'link', 'button' => $button,
            'visitor' => $visitor, 'is_unique' => $unique, 'country' => self::country($r), 'device' => $device, 'os' => $os, 'browser' => $browser,
            'referrer' => $ref ? mb_substr(preg_replace('/^www\./', '', $ref), 0, 120) : null, 'created_at' => now()]);
        if ($button === null) {
            DB::table('lk_links')->where('id', $l->id)->update(['clicks_count' => DB::raw('clicks_count + 1'), 'unique_count' => DB::raw('unique_count + '.($unique ? 1 : 0)), 'last_click_at' => now()]);
        }
    }

    // ------------------------------------------------------------------ statistiques

    public function stats(Company $c, ?int $linkId, int $days = 30): array
    {
        $from = now()->subDays($days - 1)->startOfDay();
        $q = fn () => DB::table('lk_clicks')->where('company_id', $c->id)->where('created_at', '>=', $from)->when($linkId, fn ($w) => $w->where('link_id', $linkId));
        $views = fn () => $q()->whereNull('button');
        $group = fn (string $col, int $limit = 8) => $views()->selectRaw("COALESCE($col, '') as k, count(*) as n")->groupBy('k')->orderByDesc('n')->limit($limit)->pluck('n', 'k');
        $byDay = $views()->selectRaw('DATE(created_at) as d, count(*) as n, sum(is_unique) as u')->groupBy('d')->get()->keyBy('d');
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = $from->copy()->addDays($i)->toDateString();
            $series[] = ['date' => $d, 'clicks' => (int) ($byDay[$d]->n ?? 0), 'uniques' => (int) ($byDay[$d]->u ?? 0)];
        }
        $out = [
            'days' => $days, 'clicks' => $views()->count(), 'uniques' => (int) $views()->sum('is_unique'),
            'qr_scans' => $views()->where('source', 'qr')->count(), 'series' => $series,
            'countries' => $group('country'), 'devices' => $group('device', 3), 'os' => $group('os'), 'browsers' => $group('browser'), 'referrers' => $group('referrer'),
        ];
        if ($linkId) {
            $l = DB::table('lk_links')->where('company_id', $c->id)->where('id', $linkId)->first();
            $buttons = json_decode($l->page ?? '{}', true)['buttons'] ?? [];
            $counts = $q()->whereNotNull('button')->selectRaw('button, count(*) as n')->groupBy('button')->pluck('n', 'button');
            $out['buttons'] = collect($buttons)->map(fn ($b, $i) => ['label' => $b['label'], 'platform' => $b['platform'], 'clicks' => (int) ($counts[$i] ?? 0)])->sortByDesc('clicks')->values()->all();
            $out['ctr'] = $out['clicks'] > 0 && $buttons ? round(array_sum(array_column($out['buttons'], 'clicks')) * 100 / $out['clicks'], 1) : null;
        } else {
            $out['top'] = DB::table('lk_links')->where('company_id', $c->id)->orderByDesc('clicks_count')->limit(5)->get()->map(fn ($l) => $this->row($l))->all();
        }

        return $out;
    }
}
