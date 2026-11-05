<?php

namespace App\Modules\Veille;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Veille concurrentielle : listes de valeurs, champs calculés, contrôles à l'enregistrement, tableau de bord et comparatif. */
class VeilleHooks
{
    public const KINDS = ['direct' => ['Concurrent direct', '#DC2626'], 'indirect' => ['Concurrent indirect', '#D97706'], 'substitute' => ['Produit de substitution', '#9333EA'],
        'newcomer' => ['Nouvel entrant', '#1F6FEB'], 'us' => ['Nous (référence)', '#16A34A']];

    public const COMP_STATUSES = ['watch' => ['Suivi actif', '#1F6FEB'], 'occasional' => ['Suivi occasionnel', '#64748B'], 'archived' => ['Archivé', '#94A3B8']];

    public const LEVELS = ['low' => ['Faible', '#16A34A'], 'medium' => ['Moyen', '#D97706'], 'high' => ['Fort', '#DC2626']];

    public const POSITIONING = ['low' => 'Entrée de gamme', 'mid' => 'Milieu de gamme', 'high' => 'Haut de gamme'];

    public const SCORES = ['1' => '1 — Très faible', '2' => '2 — Faible', '3' => '3 — Moyen', '4' => '4 — Bon', '5' => '5 — Excellent'];

    public const SCORE_FIELDS = ['score_price' => 'Prix', 'score_quality' => 'Qualité', 'score_service' => 'Service', 'score_range' => 'Gamme', 'score_digital' => 'Digital', 'score_reputation' => 'Notoriété'];

    public const OBS_KINDS = ['price' => 'Prix / promotion', 'product' => 'Nouveau produit ou service', 'campaign' => 'Campagne publicitaire', 'post' => 'Publication réseaux sociaux',
        'opening' => 'Ouverture / nouveau point de vente', 'event' => 'Événement / salon', 'hiring' => 'Recrutement', 'partnership' => 'Partenariat', 'review' => 'Avis clients',
        'tender' => 'Marché ou client gagné', 'press' => 'Presse / communication', 'website' => 'Changement sur son site', 'other' => 'Autre'];

    public const CHANNELS = ['field' => 'Sur le terrain', 'customer' => 'Par un client', 'website' => 'Son site web', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok',
        'linkedin' => 'LinkedIn', 'whatsapp' => 'WhatsApp', 'google' => 'Google', 'press' => 'Presse / radio / TV', 'billboard' => 'Affichage', 'event' => 'Salon / événement', 'other' => 'Autre'];

    public const OBS_STATUSES = ['new' => ['Nouvelle', '#1F6FEB'], 'analyzed' => ['Analysée', '#64748B'], 'action' => ['Action à mener', '#D97706'], 'done' => ['Traitée', '#16A34A'], 'ignored' => ['Sans suite', '#94A3B8']];

    public const SOURCES = ['manual' => 'Saisie', 'website' => 'Surveillance du site', 'mention' => 'Mention web'];

    public const PRICE_SOURCES = ['store' => 'En magasin', 'website' => 'Site web', 'social' => 'Réseaux sociaux', 'quote' => 'Devis obtenu', 'customer' => 'Rapporté par un client', 'tender' => 'Appel d\'offres'];

    public const PLATFORMS = ['meta' => 'Facebook / Instagram', 'google' => 'Google / YouTube', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'snapchat' => 'Snapchat', 'pinterest' => 'Pinterest',
        'x' => 'X (Twitter)', 'microsoft' => 'Bing / Microsoft', 'whatsapp' => 'WhatsApp', 'native' => 'Native (Taboola…)', 'billboard' => 'Affichage', 'radio' => 'Radio', 'tv' => 'Télévision',
        'press' => 'Presse écrite', 'influencer' => 'Influenceur', 'other' => 'Autre'];

    public const FORMATS = ['image' => 'Image', 'video' => 'Vidéo', 'carousel' => 'Carrousel', 'story' => 'Story / Reel', 'text' => 'Annonce texte (recherche)', 'lead' => 'Formulaire de contact',
        'message' => 'Message / WhatsApp', 'banner' => 'Bannière', 'spot' => 'Spot', 'other' => 'Autre'];

    public const NETWORKS = ['facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'x' => 'X (Twitter)',
        'whatsapp' => 'Chaîne WhatsApp', 'snapchat' => 'Snapchat', 'pinterest' => 'Pinterest', 'telegram' => 'Telegram'];

    public const WATCH_KINDS = ['home' => 'Accueil', 'prices' => 'Tarifs / catalogue', 'promo' => 'Promotions', 'news' => 'Actualités / blog', 'jobs' => 'Recrutement', 'other' => 'Autre'];

    public const FREQUENCIES = ['daily' => 'Chaque jour', 'weekly' => 'Chaque semaine'];

    public const MENTION_STATUSES = ['new' => ['Nouvelle', '#1F6FEB'], 'useful' => ['Utile', '#16A34A'], 'seen' => ['Lue', '#64748B'], 'ignored' => ['Sans intérêt', '#94A3B8']];

    protected function cid(): int
    {
        return app('tenant')->id;
    }

    public static function scoreOf(array $c): ?float
    {
        $v = array_filter(array_map(fn ($k) => $c[$k] ?? null, array_keys(self::SCORE_FIELDS)), fn ($x) => $x !== null && $x !== '');

        return $v ? round(array_sum($v) / count($v), 1) : null;
    }

    /** Indice de prix des concurrents : moyenne de (son prix / notre prix) sur le dernier relevé de chaque produit, 100 = nos prix. */
    public function priceIndexes(int $cid, ?array $ids = null): array
    {
        $rows = DB::table('vc_prices')->where('company_id', $cid)->whereNotNull('our_price')->where('our_price', '>', 0)
            ->when($ids, fn ($q) => $q->whereIn('competitor_id', $ids))->orderBy('observed_on')->orderBy('id')->get(['competitor_id', 'title', 'competitor_price', 'our_price']);
        $last = [];
        foreach ($rows as $r) {
            $last[$r->competitor_id][mb_strtolower(trim($r->title))] = (float) $r->competitor_price / (float) $r->our_price * 100;
        }

        return array_map(fn ($p) => round(array_sum($p) / count($p)), $last);
    }

    public function present(string $res, array $row): array
    {
        $cid = $this->cid();
        switch ($res) {
            case 'competitors':
                $row['score'] = self::scoreOf($row);
                $row['price_index'] = $row['kind'] === 'us' ? 100 : ($this->priceIndexes($cid, [$row['id']])[$row['id']] ?? null);
                $obs = DB::table('vc_observations')->where('company_id', $cid)->where('competitor_id', $row['id']);
                $row['observations'] = (clone $obs)->where('observed_on', '>=', now()->subDays(90)->toDateString())->count();
                $row['last_seen'] = (clone $obs)->max('observed_on');
                $row['lost_deals'] = $this->lostDeals($cid, (string) $row['title']);
                $row['audience'] = $this->audience($cid, $row['id']);
                $audit = is_array($row['site_audit'] ?? null) ? $row['site_audit'] : json_decode((string) ($row['site_audit'] ?? ''), true);
                $row['site_audit'] = $audit ?: null;
                $row['tools'] = $audit ? SiteAnalyzer::toolsText($audit) : null;
                $row['audit_summary'] = $audit ? SiteAnalyzer::summary($audit, $row['audit_at'] ?? null) : null;
                $row['ad_libraries'] = self::adLibraries($row);
                break;
            case 'observations':
                $row['has_photo'] = ! empty($row['photo_path']);
                unset($row['photo_path']);
                break;
            case 'prices':
                $row['gap'] = ($row['our_price'] ?? 0) > 0 ? round(((float) $row['competitor_price'] - (float) $row['our_price']) / (float) $row['our_price'] * 100, 1) : null;
                $prev = DB::table('vc_prices')->where('company_id', $cid)->where('competitor_id', $row['competitor_id'])->where('title', $row['title'])->where('id', '!=', $row['id'])
                    ->where(fn ($q) => $q->where('observed_on', '<', $row['observed_on'])->orWhere(fn ($w) => $w->where('observed_on', $row['observed_on'])->where('id', '<', $row['id'])))
                    ->orderByDesc('observed_on')->orderByDesc('id')->value('competitor_price');
                $row['trend'] = $prev === null ? 'Premier relevé' : (abs((float) $prev - (float) $row['competitor_price']) < 0.005 ? 'Stable'
                    : (((float) $row['competitor_price'] > (float) $prev ? 'Hausse ' : 'Baisse ').str_replace('.', ',', sprintf('%+.1f %%', ((float) $row['competitor_price'] - (float) $prev) / max(0.01, (float) $prev) * 100))));
                break;
            case 'ads':
                $row['days'] = $row['started_on'] ? (int) \Illuminate\Support\Carbon::parse($row['started_on'])->diffInDays($row['ended_on'] && ! $row['active'] ? \Illuminate\Support\Carbon::parse($row['ended_on']) : now()) + 1 : null;
                break;
            case 'audience':
                $prev = DB::table('vc_audience')->where('company_id', $cid)->where('competitor_id', $row['competitor_id'])->where('network', $row['network'])
                    ->where('recorded_on', '<', $row['recorded_on'])->orderByDesc('recorded_on')->first();
                if (! $prev) {
                    $row['growth'] = 'Premier relevé';
                } else {
                    $diff = (int) $row['followers'] - (int) $prev->followers;
                    $days = max(1, (int) \Illuminate\Support\Carbon::parse($prev->recorded_on)->diffInDays($row['recorded_on']));
                    $row['growth'] = sprintf('%+d', $diff).($prev->followers > 0 ? str_replace('.', ',', sprintf(' (%+.1f %%)', $diff / $prev->followers * 100)) : '').' en '.$days.' j';
                }
                break;
            case 'watches':
                unset($row['last_text'], $row['last_hash']);
                $row['state'] = ! $row['active'] ? 'En pause' : ($row['last_error'] ? 'Erreur : '.$row['last_error'] : (! $row['last_checked_at'] ? 'Première visite à venir'
                    : ($row['last_changed_at'] && \Illuminate\Support\Carbon::parse($row['last_changed_at'])->gt(now()->subDays(7)) ? 'Changement récent' : 'Aucun changement récent')));
                break;
            case 'alerts':
                $row['mentions'] = DB::table('vc_mentions')->where('company_id', $cid)->where('alert_id', $row['id'])->where('created_at', '>=', now()->subDays(30))->count();
                break;
        }

        return $row;
    }

    /** Liens directs vers les bibliothèques publiques des publicités, préremplis avec le nom ou le site du concurrent. */
    public static function adLibraries(array $c): array
    {
        $name = rawurlencode((string) $c['title']);
        $host = ($h = parse_url((string) ($c['website'] ?? ''), PHP_URL_HOST)) ? preg_replace('/^www\./', '', $h) : null;
        $country = strtoupper((string) (app()->bound('tenant') ? (app('tenant')->country ?? 'CI') : 'CI')) ?: 'CI';
        $country = preg_match('/^[A-Z]{2}$/', $country) ? $country : 'CI';

        return array_values(array_filter([
            ['label' => 'Meta (Facebook, Instagram)', 'url' => 'https://www.facebook.com/ads/library/?active_status=active&ad_type=all&country='.$country.'&media_type=all&search_type=keyword_unordered&q='.$name],
            ['label' => 'Google (recherche, YouTube)', 'url' => 'https://adstransparency.google.com/?region=anywhere'.($host ? '&domain='.rawurlencode($host) : '')],
            ['label' => 'LinkedIn', 'url' => 'https://www.linkedin.com/ad-library/search?companyName='.$name],
            ['label' => 'TikTok (tendances et créations)', 'url' => 'https://ads.tiktok.com/business/creativecenter/inspiration/topads/pc/fr'],
        ]));
    }

    protected function lostDeals(int $cid, string $name): ?int
    {
        static $has = null;
        $has ??= Schema::hasTable('crm_leads');
        if (! $has || mb_strlen(trim($name)) < 3) {
            return null;
        }

        return DB::table('crm_leads')->where('company_id', $cid)->where('status', 'lost')->where('lost_reason', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim($name)).'%')->count();
    }

    protected function audience(int $cid, int $competitorId): ?int
    {
        $rows = DB::table('vc_audience')->where('company_id', $cid)->where('competitor_id', $competitorId)->orderBy('recorded_on')->get(['network', 'followers']);
        if ($rows->isEmpty()) {
            return null;
        }

        return (int) $rows->keyBy('network')->sum('followers');
    }

    public function saving(string $res, array $data, ?array $old, User $u): array
    {
        if ($old && ! $u->hasPermission('veille.manage') && in_array($res, ['observations', 'prices', 'ads', 'audience', 'mentions'], true) && (int) ($old['created_by'] ?? 0) !== $u->id && $res !== 'mentions') {
            throw new \DomainException('Seul le responsable de la veille peut modifier une fiche saisie par un autre membre de l\'équipe.');
        }
        switch ($res) {
            case 'competitors':
                foreach (['website', 'facebook', 'instagram', 'tiktok', 'linkedin', 'youtube', 'x_account', 'google_maps'] as $k) {
                    if (! empty($data[$k]) && ! preg_match('#^https?://#i', $data[$k])) {
                        $data[$k] = 'https://'.ltrim($data[$k], '/');
                    }
                }
                if (isset($data['rating']) && $data['rating'] !== null && ($data['rating'] < 0 || $data['rating'] > 5)) {
                    throw new \DomainException('La note Google est comprise entre 0 et 5.');
                }
                break;
            case 'prices':
                $label = trim((string) ($data['our_product'] ?? ($old['our_product'] ?? '')));
                if ($label !== '' && (! array_key_exists('our_price', $data) || $data['our_price'] === null) && ($old === null || $label !== ($old['our_product'] ?? null) || $old['our_price'] === null)) {
                    $p = DB::table('products')->where('company_id', $this->cid())->whereNull('deleted_at')
                        ->where(fn ($q) => $q->where('sku', $label)->orWhere('barcode', $label)->orWhere('name', $label))->first(['id', 'sale_price'])
                        ?? DB::table('products')->where('company_id', $this->cid())->whereNull('deleted_at')->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $label).'%')->orderBy('name')->first(['id', 'sale_price']);
                    if ($p) {
                        $data['product_id'] = $p->id;
                        $data['our_price'] = (float) $p->sale_price;
                    }
                }
                if (($data['competitor_price'] ?? 1) < 0) {
                    throw new \DomainException('Le prix ne peut pas être négatif.');
                }
                break;
            case 'watches':
                if (isset($data['url'])) {
                    $data['url'] = WebFetcher::normalizeUrl((string) $data['url']);
                    WebFetcher::assertPublic($data['url']);
                    if ($old && $data['url'] !== $old['url']) {
                        $data += ['last_hash' => null, 'last_text' => null, 'last_error' => null];
                    }
                }
                break;
            case 'alerts':
                if (! empty($data['feed_url'])) {
                    $data['feed_url'] = WebFetcher::normalizeUrl((string) $data['feed_url']);
                    WebFetcher::assertPublic($data['feed_url']);
                }
                break;
            case 'ads':
                if (array_key_exists('active', $data) && ! $data['active'] && empty($data['ended_on']) && empty($old['ended_on'])) {
                    $data['ended_on'] = now()->toDateString();
                }
                break;
        }

        return $data;
    }

    public function saved(string $res, array $row, ?array $old, User $u): void
    {
        // Une observation à fort impact remontée du terrain prévient tout de suite les responsables.
        if ($res === 'observations' && $old === null && $row['impact'] === 'high' && ($row['source'] ?? 'manual') === 'manual') {
            $name = $row['competitor_id'] ? DB::table('vc_competitors')->where('id', $row['competitor_id'])->value('title') : null;
            \App\Models\AppNotification::send($this->cid(), array_values(array_diff($this->managers($this->cid()), [$u->id])), 'veille',
                'Veille : '.($name ? $name.' — ' : '').mb_substr($row['title'], 0, 90), 'Signalé par '.$u->name.' ('.(self::OBS_KINDS[$row['kind']] ?? 'observation').', impact fort).', 'veille', (string) $row['id']);
        }
    }

    public function deleting(string $res, array $row, User $u): void
    {
        $cid = $this->cid();
        if ($res === 'competitors') {
            if (DB::table('vc_prices')->where('company_id', $cid)->where('competitor_id', $row['id'])->exists() || DB::table('vc_audience')->where('company_id', $cid)->where('competitor_id', $row['id'])->exists()) {
                throw new \DomainException('Ce concurrent a des relevés de prix ou d\'audience : archivez-le plutôt (champ « Suivi »).');
            }
            foreach (['vc_observations', 'vc_ads', 'vc_watches', 'vc_alerts', 'vc_mentions'] as $t) {
                DB::table($t)->where('company_id', $cid)->where('competitor_id', $row['id'])->update(['competitor_id' => null]);
            }
        }
        if ($res === 'observations' && ! empty($row['photo_path'])) {
            Storage::disk('local')->delete($row['photo_path']);
        }
        if ($res === 'alerts') {
            DB::table('vc_mentions')->where('company_id', $cid)->where('alert_id', $row['id'])->where('status', '!=', 'useful')->delete();
            DB::table('vc_mentions')->where('company_id', $cid)->where('alert_id', $row['id'])->update(['alert_id' => null]);
        }
    }

    public function managers(int $companyId): array
    {
        return User::where('company_id', $companyId)->where('is_active', true)->get()->filter(fn ($x) => $x->hasPermission('veille.manage'))->pluck('id')->all();
    }

    public function dashboard(Company $c): array
    {
        $cid = $c->id;
        $since = now()->subDays(30)->toDateString();
        $obs = DB::table('vc_observations')->where('company_id', $cid);
        $prices = DB::table('vc_prices')->where('company_id', $cid)->where('observed_on', '>=', now()->subDays(90)->toDateString())->whereNotNull('our_price')->where('our_price', '>', 0)->get(['competitor_price', 'our_price']);
        $cheaper = $prices->filter(fn ($p) => (float) $p->competitor_price < (float) $p->our_price)->count();
        $competitors = DB::table('vc_competitors')->where('company_id', $cid)->where('kind', '!=', 'us')->where('status', '!=', 'archived');

        return [
            'competitors' => (clone $competitors)->count(),
            'high_threat' => (clone $competitors)->where('threat', 'high')->count(),
            'observations_30' => (clone $obs)->where('observed_on', '>=', $since)->count(),
            'high_impact' => (clone $obs)->where('observed_on', '>=', $since)->where('impact', 'high')->count(),
            'to_act' => (clone $obs)->whereIn('status', ['new', 'action'])->count(),
            'prices_90' => $prices->count(),
            'cheaper' => $cheaper,
            'avg_gap' => $prices->count() ? round($prices->avg(fn ($p) => ((float) $p->competitor_price - (float) $p->our_price) / (float) $p->our_price * 100), 1) : null,
            'ads_active' => DB::table('vc_ads')->where('company_id', $cid)->where('active', true)->count(),
            'site_changes' => DB::table('vc_watches')->where('company_id', $cid)->where('last_changed_at', '>=', now()->subDays(7))->count(),
            'watches' => DB::table('vc_watches')->where('company_id', $cid)->where('active', true)->count(),
            'mentions_new' => DB::table('vc_mentions')->where('company_id', $cid)->where('status', 'new')->count(),
            'recent' => (clone $obs)->orderByDesc('observed_on')->orderByDesc('id')->limit(6)->get(['id', 'title', 'competitor_id', 'observed_on', 'kind', 'impact'])
                ->map(fn ($o) => (array) $o + ['competitor' => $o->competitor_id ? DB::table('vc_competitors')->where('id', $o->competitor_id)->value('title') : null, 'kind_label' => self::OBS_KINDS[$o->kind] ?? $o->kind])->all(),
        ];
    }

    /** Comparatif : notes par critère, indice de prix, positionnement, présence en ligne et activité de chaque concurrent suivi. */
    public function compare(Company $c): array
    {
        $cid = $c->id;
        $list = DB::table('vc_competitors')->where('company_id', $cid)->where('status', '!=', 'archived')->orderByRaw("kind = 'us' desc")->orderBy('title')->get()->map(fn ($x) => (array) $x)->all();
        $idx = $this->priceIndexes($cid, array_column($list, 'id'));
        $out = [];
        foreach ($list as $x) {
            $audit = json_decode((string) $x['site_audit'], true) ?: null;
            $out[] = [
                'id' => $x['id'], 'title' => $x['title'], 'kind' => $x['kind'], 'threat' => $x['threat'], 'positioning' => $x['positioning'],
                'scores' => collect(self::SCORE_FIELDS)->map(fn ($l, $k) => $x[$k] === null ? null : (int) $x[$k])->all(),
                'score' => self::scoreOf($x), 'price_index' => $x['kind'] === 'us' ? 100 : ($idx[$x['id']] ?? null),
                'rating' => $x['rating'] === null ? null : (float) $x['rating'], 'reviews' => $x['reviews_count'] === null ? null : (int) $x['reviews_count'],
                'market_share' => $x['market_share'] === null ? null : (float) $x['market_share'],
                'audience' => $this->audience($cid, $x['id']),
                'observations' => DB::table('vc_observations')->where('company_id', $cid)->where('competitor_id', $x['id'])->where('observed_on', '>=', now()->subDays(90)->toDateString())->count(),
                'ads' => DB::table('vc_ads')->where('company_id', $cid)->where('competitor_id', $x['id'])->where('active', true)->count(),
                'tools' => $audit ? array_values(array_merge($audit['ads'] ?? [], $audit['marketing'] ?? [])) : [],
                'seo' => $audit['seo_score'] ?? null,
            ];
        }

        return ['criteria' => self::SCORE_FIELDS, 'competitors' => $out];
    }
}
