<?php

namespace App\Modules\Sites;

use App\Models\AppNotification;
use App\Models\Company;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteMessage;
use App\Modules\Sites\Models\SiteRequest;
use App\Services\EntityWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Sites vitrines : création depuis un modèle, contenu, données de l'ERP, contact, visites, domaines, demandes et devis. */
class SiteService
{
    /** Résolveur DNS remplaçable (tests) : fn (string $host, string $type) => array d'enregistrements dns_get_record. */
    public static ?\Closure $resolver = null;

    public const CTA_TARGETS = ['contact', 'booking', 'whatsapp', 'phone', 'events', 'products'];

    // ------------------------------------------------------------------ réglages plateforme

    public static function settings(): array
    {
        $s = (array) PlatformSetting::get('sites', []);

        return [
            'base_domain' => $s['base_domain'] ?? null,         // ex. sites.yomara.ci → {societe}.sites.yomara.ci (DNS joker *.sites.yomara.ci)
            'server_ip' => $s['server_ip'] ?? null,             // enregistrement A demandé pour un domaine personnel
            'cname_target' => $s['cname_target'] ?? ($s['base_domain'] ?? null),
            'provider_company_id' => $s['provider_company_id'] ?? User::where('is_super_admin', true)->orderBy('id')->value('company_id'),
            'auto_template' => (bool) ($s['auto_template'] ?? false), // site créé dès la demande d'un modèle
            'tax_rate' => (float) ($s['tax_rate'] ?? config('erp_sites.tax_rate', 18)),
        ];
    }

    public static function saveSettings(array $d): array
    {
        $cur = (array) PlatformSetting::get('sites', []);
        foreach (['base_domain', 'server_ip', 'cname_target'] as $k) {
            if (array_key_exists($k, $d)) {
                $cur[$k] = $d[$k] ? strtolower(trim((string) $d[$k], " ./")) : null;
            }
        }
        foreach (['provider_company_id' => 'int', 'tax_rate' => 'float', 'auto_template' => 'bool'] as $k => $t) {
            if (array_key_exists($k, $d)) {
                settype($d[$k], $t);
                $cur[$k] = $d[$k];
            }
        }
        PlatformSetting::put('sites', $cur);

        return self::settings();
    }

    // ------------------------------------------------------------------ sites

    public function url(Site $s): string
    {
        $set = self::settings();
        if ($s->custom_domain && $s->domain_status === 'active') {
            return 'https://'.$s->custom_domain;
        }

        return $set['base_domain'] ? 'https://'.$s->slug.'.'.$set['base_domain'] : url('/site/'.$s->slug);
    }

    public function previewUrl(Site $s): string
    {
        return url('/site/'.$s->slug).'?apercu='.$s->preview_token;
    }

    protected function uniqueSlug(string $base): string
    {
        $base = Str::limit(Str::slug($base) ?: 'site', 60, '');
        $slug = $base;
        $i = 1;
        while (Site::withoutGlobalScopes()->where('slug', $slug)->exists() || in_array($slug, ['www', 'app', 'api', 'admin', 'mail', 'scan'], true)) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    public function create(Company $c, string $template, ?string $name = null, ?SiteRequest $r = null): Site
    {
        $t = Templates::get($template) ?? throw new \DomainException('Modèle inconnu.');
        if (Site::withoutGlobalScopes()->where('company_id', $c->id)->exists()) {
            throw new \DomainException('Cette société a déjà un site : modifiez-le ou changez de modèle.');
        }

        return Site::withoutGlobalScopes()->create([
            'company_id' => $c->id, 'name' => Str::limit($name ?: $c->name, 160, ''), 'slug' => $this->uniqueSlug($c->slug ?: $c->name), 'template' => $t['key'],
            'theme' => [], 'content' => [], 'sections' => $t['sections'], 'seo' => [], 'preview_token' => Str::random(32), 'request_id' => $r?->id,
        ]);
    }

    /** Thème, contenu et sections effectifs : modèle + personnalisations du client. */
    public function merged(Site $s, ?Company $c = null): array
    {
        $c ??= Company::find($s->company_id);
        $t = Templates::get($s->template) ?? Templates::all()['agence-eclat'];
        $rep = ['{societe}' => $c->name, '{ville}' => $c->city ?: 'Abidjan'];
        $content = array_map(fn ($v) => is_string($v) ? strtr($v, $rep) : $v, $t['content']);
        $content['services'] = array_map(fn ($x) => array_map(fn ($v) => strtr((string) $v, $rep), $x), $content['services']);
        foreach ((array) $s->content as $k => $v) {
            if ($v !== null && $v !== '' && $v !== []) {
                $content[$k] = $v;
            }
        }
        $theme = array_replace($t['theme'], array_filter((array) $s->theme, fn ($v) => $v !== null && $v !== ''));

        return ['template' => $t, 'theme' => $theme, 'content' => $content, 'sections' => $s->sections ?: $t['sections'], 'seo' => (array) $s->seo];
    }

    public const CONTENT_KEYS = ['hero_title', 'hero_subtitle', 'cta_label', 'cta_target', 'about_title', 'about_text', 'services_title', 'hours', 'announcement', 'whatsapp', 'address', 'map_url'];

    public function update(Site $s, array $d): Site
    {
        if (isset($d['template'])) {
            Templates::get($d['template']) ?? throw new \DomainException('Modèle inconnu.');
            $s->template = $d['template'];
        }
        if (isset($d['name'])) {
            $s->name = Str::limit(trim((string) $d['name']), 160, '') ?: $s->name;
        }
        if (isset($d['theme']) && is_array($d['theme'])) {
            $th = (array) $s->theme;
            foreach (['primary', 'dark', 'accent', 'background'] as $k) {
                if (array_key_exists($k, $d['theme'])) {
                    $v = $d['theme'][$k];
                    if ($v !== null && ! preg_match('/^#[0-9a-fA-F]{6}$/', (string) $v)) {
                        throw new \DomainException('Couleur invalide : utilisez le format #RRGGBB.');
                    }
                    $th[$k] = $v;
                }
            }
            foreach (['hero' => ['gradient', 'centered', 'split', 'image'], 'cards' => ['shadow', 'outline', 'flat'], 'nav' => ['light', 'transparent', 'dark'],
                'heading_font' => array_unique(array_merge(array_column(Templates::STYLES, 'heading'), ['Montserrat', 'Raleway', 'Lora', 'Nunito', 'DM Serif Display', 'Merriweather'])),
                'body_font' => array_unique(array_merge(array_column(Templates::STYLES, 'body'), ['Montserrat', 'Raleway', 'Lora', 'Nunito', 'Open Sans', 'Roboto']))] as $k => $allowed) {
                if (array_key_exists($k, $d['theme'])) {
                    $v = $d['theme'][$k];
                    if ($v !== null && ! in_array($v, $allowed, true)) {
                        throw new \DomainException('Valeur de thème non prise en charge : '.$k.'.');
                    }
                    $th[$k] = $v;
                }
            }
            if (array_key_exists('radius', $d['theme'])) {
                $th['radius'] = $d['theme']['radius'] === null ? null : max(0, min(28, (int) $d['theme']['radius']));
            }
            $s->theme = $th;
        }
        if (isset($d['content']) && is_array($d['content'])) {
            $ct = (array) $s->content;
            $in = $d['content'];
            foreach (self::CONTENT_KEYS as $k) {
                if (array_key_exists($k, $in)) {
                    $v = is_string($in[$k]) ? trim($in[$k]) : $in[$k];
                    $ct[$k] = $v === '' ? null : Str::limit((string) $v, in_array($k, ['about_text', 'hero_subtitle'], true) ? 2000 : 300, '');
                }
            }
            if (isset($ct['cta_target']) && ! in_array($ct['cta_target'], self::CTA_TARGETS, true)) {
                $ct['cta_target'] = 'contact';
            }
            $lists = ['services' => [8, ['icon', 'title', 'text']], 'stats' => [4, ['value', 'label']], 'testimonials' => [8, ['name', 'role', 'text']], 'faq' => [12, ['q', 'a']]];
            foreach ($lists as $k => [$max, $fields]) {
                if (array_key_exists($k, $in)) {
                    $rows = [];
                    foreach (array_slice((array) $in[$k], 0, $max) as $row) {
                        $r = [];
                        foreach ($fields as $f) {
                            $r[$f] = Str::limit(trim((string) ($row[$f] ?? '')), $f === 'text' || $f === 'a' ? 600 : 120, '');
                        }
                        if (isset($r['icon']) && ! in_array($r['icon'], Templates::ICONS, true)) {
                            $r['icon'] = 'check';
                        }
                        if (trim(implode('', array_diff_key($r, ['icon' => 1]))) !== '') {
                            $rows[] = $r;
                        }
                    }
                    $ct[$k] = $rows ?: null;
                }
            }
            if (array_key_exists('socials', $in)) {
                $so = [];
                foreach (['facebook', 'instagram', 'linkedin', 'tiktok', 'youtube'] as $k) {
                    $v = trim((string) ($in['socials'][$k] ?? ''));
                    if ($v !== '') {
                        $so[$k] = preg_match('#^https?://#i', $v) ? $v : 'https://'.ltrim($v, '/');
                    }
                }
                $ct['socials'] = $so ?: null;
            }
            if (array_key_exists('product_ids', $in)) {
                $ct['product_ids'] = array_values(array_slice(array_filter((array) $in['product_ids'], fn ($x) => is_string($x) && Str::isUuid($x)), 0, 24)) ?: null;
            }
            $s->content = $ct;
        }
        if (isset($d['sections']) && is_array($d['sections'])) {
            $sec = array_values(array_unique(array_filter($d['sections'], fn ($x) => is_string($x) && isset(Templates::SECTIONS[$x]))));
            if (! in_array('hero', $sec, true)) {
                array_unshift($sec, 'hero');
            }
            $s->sections = $sec;
        }
        if (isset($d['seo']) && is_array($d['seo'])) {
            $tracking = (array) ($d['seo']['tracking'] ?? ((array) $s->seo)['tracking'] ?? []);
            $clean = [];
            foreach (\App\Modules\Acquisition\AcquisitionController::TRACKING as $k => [$label, $re]) {
                $v = is_scalar($tracking[$k] ?? null) ? trim((string) $tracking[$k]) : '';
                if ($v !== '') {
                    preg_match($re, $v) || throw new \DomainException($label.' : format incorrect ('.$v.').');
                    $clean[$k] = $v;
                }
            }
            $s->seo = ['title' => Str::limit(trim((string) ($d['seo']['title'] ?? '')), 70, '') ?: null, 'description' => Str::limit(trim((string) ($d['seo']['description'] ?? '')), 170, '') ?: null] + ($clean ? ['tracking' => $clean] : []);
        }
        $s->save();

        return $s;
    }

    public const IMAGE_SLOTS = ['hero', 'about', 'logo', 'gallery'];

    public function uploadImage(Site $s, UploadedFile $f, string $slot): Site
    {
        if (! in_array($slot, self::IMAGE_SLOTS, true)) {
            throw new \DomainException('Emplacement d\'image inconnu.');
        }
        $ext = strtolower($f->getClientOriginalExtension());
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) || $f->getSize() > 4 * 1024 * 1024) {
            throw new \DomainException('Image PNG, JPG ou WEBP de 4 Mo au plus.');
        }
        $ct = (array) $s->content;
        if ($slot === 'gallery' && count($ct['gallery'] ?? []) >= 12) {
            throw new \DomainException('12 photos au plus dans la galerie.');
        }
        $path = 'sites/'.$s->company_id.'/'.$s->id.'/'.$slot.'-'.Str::random(8).'.'.$ext;
        Storage::disk('public')->put($path, (string) file_get_contents($f->getRealPath()));
        if ($slot === 'gallery') {
            $ct['gallery'] = array_values(array_merge($ct['gallery'] ?? [], [$path]));
        } else {
            if (! empty($ct[$slot.'_image'])) {
                Storage::disk('public')->delete($ct[$slot.'_image']);
            }
            $ct[$slot.'_image'] = $path;
        }
        $s->content = $ct;
        $s->save();

        return $s;
    }

    public function removeImage(Site $s, string $path): Site
    {
        $ct = (array) $s->content;
        foreach (['hero_image', 'about_image', 'logo_image'] as $k) {
            if (($ct[$k] ?? null) === $path) {
                $ct[$k] = null;
            }
        }
        $ct['gallery'] = array_values(array_filter($ct['gallery'] ?? [], fn ($p) => $p !== $path)) ?: null;
        if (str_starts_with($path, 'sites/'.$s->company_id.'/')) {
            Storage::disk('public')->delete($path);
        }
        $s->content = $ct;
        $s->save();

        return $s;
    }

    public static function img(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    public function publish(Site $s, bool $on): Site
    {
        if ($s->status === 'suspended') {
            throw new \DomainException('Site suspendu par la plateforme : contactez le support.');
        }
        $s->status = $on ? 'published' : 'draft';
        if ($on && ! $s->published_at) {
            $s->published_at = now();
        }
        $s->save();

        return $s;
    }

    public function toApi(Site $s): array
    {
        $m = $this->merged($s);
        $ct = $m['content'];
        $images = ['hero' => self::img($ct['hero_image'] ?? null), 'about' => self::img($ct['about_image'] ?? null), 'logo' => self::img($ct['logo_image'] ?? null),
            'gallery' => array_map(fn ($p) => ['path' => $p, 'url' => self::img($p)], $ct['gallery'] ?? [])];

        return $s->only(['id', 'name', 'slug', 'template', 'status', 'custom_domain', 'domain_status', 'domain_error', 'published_at', 'created_at', 'updated_at'])
            + ['theme' => $m['theme'], 'content' => $ct, 'sections' => $m['sections'], 'seo' => $m['seo'], 'overrides' => ['theme' => (array) $s->theme, 'content' => (array) $s->content],
                'template_name' => $m['template']['name'], 'images' => $images, 'url' => $this->url($s), 'preview_url' => $this->previewUrl($s),
                'domain_checked_at' => $s->domain_checked_at?->toIso8601String(), 'dns' => $s->custom_domain ? $this->dnsRecords($s) : [],
                'unread' => SiteMessage::withoutGlobalScopes()->where('site_id', $s->id)->whereNull('read_at')->count()];
    }

    // ------------------------------------------------------------------ rendu (données de l'ERP)

    public function viewData(Site $s, bool $preview = false): array
    {
        $c = Company::find($s->company_id);
        $m = $this->merged($s, $c);
        $ct = $m['content'];
        app()->instance('tenant', $c);
        $sections = $m['sections'];
        $products = [];
        if (in_array('products', $sections, true)) {
            $q = \App\Modules\Core\Models\Product::where('active', true)->whereNull('parent_id')->where('sale_price', '>', 0);
            $ids = $ct['product_ids'] ?? null;
            $rows = $ids ? $q->whereIn('id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids)) : $q->orderByDesc('updated_at')->limit(8)->get();
            foreach ($rows as $p) {
                $img = is_string($p->image) && (str_starts_with($p->image, 'http') || str_starts_with($p->image, 'data:image')) ? $p->image : null;
                $price = ShopService::ttc((float) $p->sale_price, (float) $p->tax_rate);
                $from = false;
                if ($p->is_template) {
                    $vs = \App\Modules\Core\Models\Product::where('parent_id', $p->id)->where('active', true)->where('sale_price', '>', 0)->get(['sale_price', 'tax_rate']);
                    if ($vs->isEmpty()) {
                        continue;
                    }
                    $all = $vs->map(fn ($v) => ShopService::ttc((float) $v->sale_price, (float) $v->tax_rate));
                    [$price, $from] = [$all->min(), $all->unique()->count() > 1];
                }
                $products[] = ['id' => $p->id, 'slug' => Str::slug($p->name) ?: 'produit', 'name' => $p->name, 'price' => $price, 'from' => $from, 'is_template' => (bool) $p->is_template,
                    'description' => Str::limit((string) $p->description, 140), 'image' => $img];
            }
        }
        $events = [];
        if (in_array('events', $sections, true) && $c->hasModule('evenements') && class_exists(\App\Modules\Evenements\Models\Event::class)) {
            foreach (\App\Modules\Evenements\Models\Event::where('end_date', '>=', now()->toDateString())->whereIn('status', ['open'])->orderBy('start_date')->limit(6)->get() as $e) {
                $events[] = ['name' => $e->name, 'date' => $e->start_date, 'end' => $e->end_date, 'venue' => trim($e->venue.($e->city ? ', '.$e->city : ''), ', '),
                    'description' => Str::limit((string) $e->description, 160), 'url' => $e->ticketing_enabled && $e->public_token ? url('/billetterie/'.$e->public_token) : null];
            }
        }
        $booking = $c->hasModule('rendez_vous') ? url('/rdv/'.$c->slug) : null;
        $jobs = null;
        if ($c->hasModule('recrutement') && class_exists(\App\Modules\Recrutement\Models\Job::class)) {
            $n = \App\Modules\Recrutement\Models\Job::where('status', 'open')->where('published', true)->where(fn ($q) => $q->whereNull('closes_at')->orWhere('closes_at', '>=', now()->toDateString()))->count();
            $jobs = $n ? ['count' => $n, 'url' => url('/emplois/'.$c->slug)] : null;
        }
        $posts = $s->id ? \App\Modules\Sites\Models\SitePost::withoutGlobalScopes()->where('site_id', $s->id)->where('published', true)->orderByDesc('published_at')->limit(3)->get() : collect();
        $shopOn = $s->id && app(ShopService::class)->settings($s)['enabled'];
        $reviews = $s->id ? \App\Modules\Sites\Models\SiteReview::withoutGlobalScopes()->where('site_id', $s->id)->where('status', 'published')->whereNotNull('comment')->where('rating', '>=', 4)->orderByDesc('id')->limit(6)->get() : collect();
        $sections = array_values(array_filter($sections, fn ($k) => match ($k) {
            'products' => (bool) $products, 'events' => (bool) $events, 'booking' => (bool) $booking, 'jobs' => (bool) $jobs,
            'blog' => $posts->isNotEmpty(), 'gallery' => ! empty($ct['gallery']), 'testimonials' => ! empty($ct['testimonials']) || $reviews->isNotEmpty(), 'faq' => ! empty($ct['faq']), 'stats' => ! empty($ct['stats']),
            default => true,
        }));
        $wa = preg_replace('/\D/', '', (string) ($ct['whatsapp'] ?? $c->phone));
        if ($wa && strlen($wa) === 10) {
            $wa = '225'.$wa;
        }
        $cta = match ($ct['cta_target'] ?? 'contact') {
            'booking' => $booking ?: '#contact', 'whatsapp' => $wa ? 'https://wa.me/'.$wa : '#contact', 'phone' => $c->phone ? 'tel:'.preg_replace('/[^\d+]/', '', $c->phone) : '#contact',
            'events' => $events ? '#evenements' : '#contact', 'products' => $products ? '#produits' : '#contact', default => '#contact',
        };
        $logo = self::img($ct['logo_image'] ?? null) ?? ($c->logo && (str_starts_with($c->logo, 'http') || str_starts_with($c->logo, 'data:image')) ? $c->logo : null);

        return [
            'site' => $s, 'company' => $c, 'theme' => $m['theme'], 'c' => $ct, 'sections' => $sections, 'seo' => $m['seo'], 'template' => $m['template'],
            'products' => $products, 'events' => $events, 'booking' => $booking, 'jobs' => $jobs, 'whatsapp' => $wa ?: null, 'cta' => $cta, 'logo' => $logo,
            'hero_image' => self::img($ct['hero_image'] ?? null), 'about_image' => self::img($ct['about_image'] ?? null),
            'gallery' => array_map(fn ($p) => self::img($p), $ct['gallery'] ?? []), 'preview' => $preview, 'action' => $this->contactAction($s), 'tracking' => $preview ? [] : self::tracking($s, $c),
            'currency' => in_array($c->currency, ['XOF', 'XAF', null], true) ? 'F CFA' : $c->currency, 'marketing' => $c->hasModule('marketing'),
            'shop_enabled' => $shopOn, 'has_blog' => $s->id && \App\Modules\Sites\Models\SitePost::withoutGlobalScopes()->where('site_id', $s->id)->where('published', true)->exists(),
            'posts' => $posts->map(fn ($p) => ['title' => $p->title, 'slug' => $p->slug, 'excerpt' => $p->excerpt ?: \Illuminate\Support\Str::limit(strip_tags((string) $p->body), 150), 'date' => $p->published_at, 'cover' => self::img($p->cover_path)])->all(),
            'page' => 'home',
            'reviews' => $reviews->map(fn ($r) => ['name' => $r->name, 'rating' => $r->rating, 'text' => $r->comment, 'reply' => $r->reply])->all(),
            'rating' => $s->id ? app(ShopService::class)->ratings($s) : ['count' => 0, 'average' => null],
        ];
    }

    public function contactAction(Site $s): string
    {
        return url('/site/'.$s->slug.'/contact');
    }

    public function trackVisit(Site $s, ?string $referrer): void
    {
        $ref = $referrer ? strtolower((string) parse_url($referrer, PHP_URL_HOST)) : '';
        $ref = preg_replace('/^(www\.|m\.|l\.|lm\.)/', '', $ref);
        $own = [$s->custom_domain, parse_url(url('/'), PHP_URL_HOST), self::settings()['base_domain'] ? $s->slug.'.'.self::settings()['base_domain'] : null];
        $ref = $ref && ! in_array($ref, array_filter($own), true) ? $ref : 'direct';
        $today = now()->toDateString();
        DB::transaction(function () use ($s, $today, $ref) {
            $row = DB::table('ws_visits')->where('site_id', $s->id)->where('date', $today)->lockForUpdate()->first();
            if (! $row) {
                DB::table('ws_visits')->insert(['site_id' => $s->id, 'date' => $today, 'views' => 1, 'referrers' => json_encode([$ref => 1])]);

                return;
            }
            $r = (array) json_decode((string) $row->referrers, true);
            $r[$ref] = ($r[$ref] ?? 0) + 1;
            if (count($r) > 40) {
                arsort($r);
                $r = array_slice($r, 0, 40, true);
            }
            DB::table('ws_visits')->where('id', $row->id)->update(['views' => $row->views + 1, 'referrers' => json_encode($r)]);
        });
    }

    public function stats(Site $s, int $days = 30): array
    {
        $from = now()->subDays($days - 1)->toDateString();
        $rows = DB::table('ws_visits')->where('site_id', $s->id)->where('date', '>=', $from)->get()->keyBy(fn ($r) => substr((string) $r->date, 0, 10));
        $by = [];
        $refs = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i)->toDateString();
            $by[] = ['date' => $d, 'views' => (int) ($rows[$d]->views ?? 0)];
            foreach ((array) json_decode((string) ($rows[$d]->referrers ?? '[]'), true) as $k => $n) {
                $refs[$k] = ($refs[$k] ?? 0) + $n;
            }
        }
        arsort($refs);
        $msgs = SiteMessage::withoutGlobalScopes()->where('site_id', $s->id);

        return ['views' => array_sum(array_column($by, 'views')), 'by_day' => $by, 'referrers' => collect($refs)->take(8)->map(fn ($n, $k) => ['source' => $k, 'views' => $n])->values()->all(),
            'messages' => (clone $msgs)->where('created_at', '>=', $from)->count(), 'unread' => (clone $msgs)->whereNull('read_at')->count(),
            'total_views' => (int) DB::table('ws_visits')->where('site_id', $s->id)->sum('views')];
    }

    /** Balises de suivi du site : celles de la société (Publicité & acquisition), remplacées par celles réglées sur le site. */
    public static function tracking(Site $s, Company $c): array
    {
        return array_filter(array_merge((array) $c->setting('tracking', []), (array) (((array) $s->seo)['tracking'] ?? [])), fn ($v) => is_string($v) && $v !== '');
    }

    /** Message du formulaire de contact : enregistré, opportunité CRM, contact Marketing (avec accord), équipe prévenue. */
    public function contact(Site $s, array $d): SiteMessage
    {
        $c = Company::find($s->company_id);
        app()->instance('tenant', $c);
        $msg = SiteMessage::create(['site_id' => $s->id, 'name' => Str::limit(trim($d['name']), 160, ''), 'email' => $d['email'] ?? null, 'phone' => $d['phone'] ?? null,
            'subject' => isset($d['subject']) ? Str::limit(trim((string) $d['subject']), 190, '') : null, 'message' => Str::limit(trim($d['message']), 5000, ''), 'consent' => (bool) ($d['consent'] ?? false),
            'utm_source' => isset($d['utm_source']) ? Str::limit((string) $d['utm_source'], 100, '') : null, 'utm_campaign' => isset($d['utm_campaign']) ? Str::limit(Str::slug((string) $d['utm_campaign'], '_'), 150, '') : null]);
        if ($c->hasModule('crm')) {
            rescue(function () use ($msg, $d, $c) {
                $lead = new \App\Modules\Crm\Models\Lead(['title' => 'Site web — '.($msg->subject ?: $msg->name), 'contact_name' => $msg->name, 'email' => $msg->email, 'phone' => $msg->phone,
                    'source' => 'website', 'notes' => $msg->message, 'utm_source' => $msg->utm_source, 'utm_medium' => isset($d['utm_medium']) ? Str::limit((string) $d['utm_medium'], 100, '') : null,
                    'utm_campaign' => $msg->utm_campaign, 'utm_content' => isset($d['utm_content']) ? Str::limit((string) $d['utm_content'], 150, '') : null]);
                if ($msg->utm_campaign && \Illuminate\Support\Facades\Schema::hasTable('ac_campaigns')) {
                    $lead->forceFill(['ad_campaign_id' => \Illuminate\Support\Facades\DB::table('ac_campaigns')->where('company_id', $c->id)->where('utm_campaign', Str::slug($msg->utm_campaign, '_'))->value('id')]);
                }
                $lead->save();
                $msg->forceFill(['lead_id' => (string) $lead->id])->save();
            }, null, false);
        }
        if ($c->hasModule('marketing') && ($msg->email || $msg->phone)) {
            rescue(function () use ($msg) {
                $C = \App\Modules\Marketing\Models\Contact::class;
                $L = \App\Modules\Marketing\Models\MkList::class;
                $contact = $msg->email ? $C::where('email', mb_strtolower($msg->email))->first() : null;
                $contact ??= new $C(['source' => 'website']);
                [$first, $last] = str_contains($msg->name, ' ') ? explode(' ', $msg->name, 2) : [$msg->name, null];
                $contact->first_name ??= $first;
                $contact->last_name ??= $last;
                $contact->email ??= $msg->email ? mb_strtolower($msg->email) : null;
                $contact->phone ??= $msg->phone;
                if ($msg->consent) {
                    $contact->opt_in = $contact->opt_in === 'pending' ? 'confirmed' : ($contact->opt_in ?? 'confirmed');
                    $contact->consent_at ??= now();
                }
                $contact->last_activity_at = now();
                $contact->save();
                $list = $L::firstOrCreate(['name' => 'Contacts du site web'], ['description' => 'Personnes ayant écrit via le formulaire du site vitrine (ajout automatique)']);
                $contact->lists()->syncWithoutDetaching([$list->id]);
            }, null, false);
        }
        $users = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('sites.manage') || $u->hasPermission('crm.manage'))->pluck('id');
        AppNotification::send($c->id, $users, 'site_message', 'Nouveau message du site : '.$msg->name, Str::limit($msg->message, 140), 'sites', (string) $msg->id);

        return $msg;
    }

    // ------------------------------------------------------------------ domaine personnel

    public static function normalizeDomain(string $d): string
    {
        $d = strtolower(trim($d));
        $d = preg_replace('#^https?://#', '', $d);
        $d = explode('/', $d)[0];
        $d = preg_replace('/^www\./', '', rtrim($d, '.'));
        if (! preg_match('/^(?=.{4,190}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $d)) {
            throw new \DomainException('Nom de domaine invalide (ex. : monentreprise.ci).');
        }

        return $d;
    }

    public function setDomain(Site $s, ?string $domain): Site
    {
        if (! $domain) {
            $s->forceFill(['custom_domain' => null, 'domain_status' => 'none', 'domain_token' => null, 'domain_error' => null, 'domain_checked_at' => null])->save();

            return $s;
        }
        $d = self::normalizeDomain($domain);
        $set = self::settings();
        $platform = array_filter([parse_url(config('app.url'), PHP_URL_HOST), $set['base_domain']]);
        foreach ($platform as $p) {
            if ($d === $p || str_ends_with($d, '.'.$p)) {
                throw new \DomainException('Ce domaine appartient à la plateforme.');
            }
        }
        if (Site::withoutGlobalScopes()->where('custom_domain', $d)->where('id', '!=', $s->id)->exists()) {
            throw new \DomainException('Ce domaine est déjà relié à un autre site.');
        }
        if ($s->custom_domain !== $d) {
            $s->forceFill(['custom_domain' => $d, 'domain_status' => 'pending', 'domain_token' => Str::lower(Str::random(24)), 'domain_error' => null, 'domain_checked_at' => null])->save();
        }

        return $s;
    }

    public function cnameTarget(Site $s): ?string
    {
        $set = self::settings();

        return $set['base_domain'] ? $s->slug.'.'.$set['base_domain'] : $set['cname_target'];
    }

    /** Enregistrements à créer chez le registraire du client. */
    public function dnsRecords(Site $s): array
    {
        $set = self::settings();
        $r = [['type' => 'TXT', 'host' => '_yomara', 'value' => 'yomara-site='.$s->domain_token, 'purpose' => 'Preuve que le domaine vous appartient']];
        if ($set['server_ip']) {
            $r[] = ['type' => 'A', 'host' => '@', 'value' => $set['server_ip'], 'purpose' => 'Domaine principal vers le serveur'];
        }
        if ($t = $this->cnameTarget($s)) {
            $r[] = ['type' => 'CNAME', 'host' => 'www', 'value' => $t, 'purpose' => 'www vers votre site'];
        } elseif ($set['server_ip']) {
            $r[] = ['type' => 'A', 'host' => 'www', 'value' => $set['server_ip'], 'purpose' => 'www vers le serveur'];
        }

        return $r;
    }

    protected function lookup(string $host, string $type): array
    {
        $fn = self::$resolver ?? function (string $h, string $t) {
            $map = ['TXT' => DNS_TXT, 'A' => DNS_A, 'CNAME' => DNS_CNAME];

            return @dns_get_record($h, $map[$t] ?? DNS_ANY) ?: [];
        };

        return (array) $fn($host, $type);
    }

    /** Vérifie la propriété (TXT) puis le pointage (A ou CNAME) : pending → verified → active. */
    public function checkDomain(Site $s): Site
    {
        if (! $s->custom_domain) {
            return $s;
        }
        $d = $s->custom_domain;
        $set = self::settings();
        $txt = collect($this->lookup('_yomara.'.$d, 'TXT'))->map(fn ($r) => trim((string) ($r['txt'] ?? implode('', (array) ($r['entries'] ?? [])))))->all();
        $owned = in_array('yomara-site='.$s->domain_token, $txt, true);
        $ips = collect(array_merge($this->lookup($d, 'A'), $this->lookup('www.'.$d, 'A')))->pluck('ip')->filter()->unique()->all();
        $cnames = collect($this->lookup('www.'.$d, 'CNAME'))->pluck('target')->map(fn ($t) => rtrim(strtolower((string) $t), '.'))->all();
        $target = $this->cnameTarget($s);
        $points = ($set['server_ip'] && in_array($set['server_ip'], $ips, true)) || ($target && in_array(strtolower($target), $cnames, true));
        [$status, $err] = match (true) {
            ! $owned => ['pending', 'Enregistrement TXT « _yomara » introuvable (la propagation DNS peut prendre jusqu\'à 24 h).'],
            ! $points => ['verified', 'Domaine vérifié : il reste à le faire pointer vers le serveur (enregistrement A ou CNAME).'],
            default => ['active', null],
        };
        $was = $s->domain_status;
        $s->forceFill(['domain_status' => $status, 'domain_error' => $err, 'domain_checked_at' => now()])->save();
        if ($status === 'active' && $was !== 'active') {
            $users = User::where('company_id', $s->company_id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('sites.manage'))->pluck('id');
            AppNotification::send($s->company_id, $users, 'site_domain', 'Domaine actif : '.$d, 'Votre site est accessible sur https://'.$d.' (certificat SSL activé par la plateforme).', 'sites', (string) $s->id);
            $this->notifyAdmins('Domaine à sécuriser (SSL) : '.$d, 'Le domaine pointe vers le serveur : générez le certificat HTTPS (certbot / AutoSSL).', $s->id);
        }

        return $s;
    }

    // ------------------------------------------------------------------ demandes et devis

    public static function pricing(): array
    {
        return config('erp_sites');
    }

    /** Estimation d'un site sur mesure à partir des besoins exprimés. */
    public static function estimate(array $needs): array
    {
        $p = self::pricing();
        $lines = [['key' => 'base', 'description' => $p['base']['label'], 'quantity' => 1, 'unit_price' => (float) $p['base']['price']]];
        $pages = max(1, min(60, (int) ($needs['page_count'] ?? count((array) ($needs['pages'] ?? [])) ?: 5)));
        if ($pages > $p['included_pages']) {
            $lines[] = ['key' => 'extra_page', 'description' => $p['extra_page']['label'], 'quantity' => $pages - $p['included_pages'], 'unit_price' => (float) $p['extra_page']['price']];
        }
        foreach (array_unique((array) ($needs['features'] ?? [])) as $f) {
            if (isset($p['features'][$f])) {
                $lines[] = ['key' => $f, 'description' => $p['features'][$f]['label'], 'quantity' => 1, 'unit_price' => (float) $p['features'][$f]['price']];
            }
        }
        $sub = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines));
        if (! empty($needs['urgent'])) {
            $lines[] = ['key' => 'urgent', 'description' => $p['urgent']['label'].' (+'.$p['urgent']['percent'].' %)', 'quantity' => 1, 'unit_price' => round($sub * $p['urgent']['percent'] / 100)];
            $sub = array_sum(array_map(fn ($l) => $l['quantity'] * $l['unit_price'], $lines));
        }
        $tax = round($sub * self::settings()['tax_rate'] / 100);

        return ['lines' => $lines, 'pages' => $pages, 'subtotal' => $sub, 'tax_rate' => self::settings()['tax_rate'], 'tax' => $tax, 'total' => $sub + $tax];
    }

    public static function cleanNeeds(array $n): array
    {
        $p = self::pricing();

        return [
            'pages' => array_values(array_slice(array_filter(array_map(fn ($x) => Str::limit(trim((string) $x), 80, ''), (array) ($n['pages'] ?? []))), 0, 60)),
            'page_count' => isset($n['page_count']) ? max(1, min(60, (int) $n['page_count'])) : null,
            'features' => array_values(array_intersect(array_unique((array) ($n['features'] ?? [])), array_keys($p['features']))),
            'description' => Str::limit(trim((string) ($n['description'] ?? '')), 4000, '') ?: null,
            'references' => Str::limit(trim((string) ($n['references'] ?? '')), 1000, '') ?: null,
            'colors' => Str::limit(trim((string) ($n['colors'] ?? '')), 200, '') ?: null,
            'budget' => isset($n['budget']) && $n['budget'] !== '' ? max(0, (float) $n['budget']) : null,
            'deadline' => isset($n['deadline']) && $n['deadline'] ? Carbon::parse($n['deadline'])->toDateString() : null,
            'urgent' => (bool) ($n['urgent'] ?? false),
            'has_logo' => (bool) ($n['has_logo'] ?? false), 'has_content' => (bool) ($n['has_content'] ?? false),
            'domain' => isset($n['domain']) && $n['domain'] ? Str::limit(strtolower(trim((string) $n['domain'])), 190, '') : null,
        ];
    }

    public function submit(Company $c, ?User $u, array $d): SiteRequest
    {
        $type = $d['type'] === 'custom' ? 'custom' : 'template';
        if ($type === 'template' && ! Templates::get($d['template'] ?? null)) {
            throw new \DomainException('Choisissez un modèle de la galerie.');
        }
        $open = SiteRequest::withoutGlobalScopes()->where('company_id', $c->id)->whereIn('status', ['submitted', 'quoted', 'accepted', 'in_progress'])->where('type', $type)->exists();
        if ($open) {
            throw new \DomainException('Une demande est déjà en cours de traitement.');
        }
        $needs = $type === 'custom' ? self::cleanNeeds((array) ($d['needs'] ?? [])) : null;
        if ($type === 'custom' && ! $needs['description'] && ! $needs['features'] && ! $needs['pages']) {
            throw new \DomainException('Décrivez votre projet : pages souhaitées, fonctionnalités ou description.');
        }
        $year = now()->year;
        $n = SiteRequest::withoutGlobalScopes()->where('number', 'like', 'SITE-'.$year.'-%')->count() + 1;
        $r = SiteRequest::withoutGlobalScopes()->create([
            'company_id' => $c->id, 'number' => 'SITE-'.$year.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'type' => $type, 'template' => $d['template'] ?? null,
            'site_name' => Str::limit(trim((string) ($d['site_name'] ?? $c->name)) ?: $c->name, 160, ''), 'sector' => $d['sector'] ?? (Templates::get($d['template'] ?? null)['sector'] ?? null),
            'needs' => $needs, 'estimate' => $needs ? self::estimate($needs)['total'] : 0, 'user_id' => $u?->id,
        ]);
        $this->notifyAdmins('Demande de site '.($type === 'custom' ? 'sur mesure' : 'vitrine').' : '.$c->name, $r->number.' · '.($type === 'custom' ? 'estimation '.number_format($r->estimate, 0, ',', ' ').' F' : Templates::get($r->template)['name']), $r->id);
        if ($type === 'template' && self::settings()['auto_template'] && ! Site::withoutGlobalScopes()->where('company_id', $c->id)->exists()) {
            $this->deliver($r);
        }

        return $r->fresh();
    }

    protected function notifyAdmins(string $title, string $body, $ref): void
    {
        foreach (User::where('is_super_admin', true)->where('is_active', true)->get() as $a) {
            AppNotification::send($a->company_id, [$a->id], 'site_request', $title, $body, 'admin_sites', (string) $ref);
        }
    }

    protected function notifyClient(SiteRequest $r, string $title, string $body): void
    {
        $users = User::where('company_id', $r->company_id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('sites.manage'))->pluck('id');
        AppNotification::send($r->company_id, $users, 'site_request', $title, $body, 'sites', (string) $r->id);
    }

    /** Crée le site de la demande (modèle choisi, ou modèle de départ pour le sur mesure) et le relie à l'ERP du client. */
    public function deliver(SiteRequest $r, ?string $template = null): Site
    {
        if (in_array($r->status, ['delivered', 'rejected', 'cancelled', 'refused'], true)) {
            throw new \DomainException('Demande déjà traitée.');
        }
        if ($r->type === 'custom' && ! in_array($r->status, ['accepted', 'in_progress'], true)) {
            throw new \DomainException('Le client doit d\'abord accepter le devis.');
        }
        $c = Company::findOrFail($r->company_id);
        $tpl = $template ?: $r->template ?: (($r->sector ?: 'agence').'-eclat');
        $site = Site::withoutGlobalScopes()->where('company_id', $c->id)->first();
        if ($site) {
            $site->forceFill(['template' => Templates::get($tpl) ? $tpl : $site->template, 'request_id' => $r->id])->save();
        } else {
            $site = $this->create($c, $tpl, $r->site_name, $r);
        }
        $r->forceFill(['status' => 'delivered', 'site_id' => $site->id, 'decided_at' => now()])->save();
        $this->notifyClient($r, 'Votre site est prêt', 'Personnalisez-le puis publiez-le depuis le menu « Site web ».');

        return $site;
    }

    public function reject(SiteRequest $r, ?string $reason): SiteRequest
    {
        if (in_array($r->status, ['delivered', 'rejected', 'cancelled'], true)) {
            throw new \DomainException('Demande déjà traitée.');
        }
        $r->forceFill(['status' => 'rejected', 'admin_note' => $reason, 'decided_at' => now()])->save();
        $this->notifyClient($r, 'Demande de site refusée', $reason ?: $r->number);

        return $r;
    }

    public function providerCompany(): ?Company
    {
        $id = self::settings()['provider_company_id'];

        return $id ? Company::find($id) : null;
    }

    /**
     * Devis du site sur mesure, émis dans l'ERP du prestataire (Yomara Technologies) au nom du client.
     * Lignes = estimation, éventuellement remplacées ou complétées par le super admin.
     */
    public function quote(SiteRequest $r, ?array $lines = null, ?string $note = null, float $discountPercent = 0): SiteRequest
    {
        if ($r->type !== 'custom' || ! in_array($r->status, ['submitted', 'quoted', 'refused'], true)) {
            throw new \DomainException('Devis possible seulement pour une demande sur mesure en attente.');
        }
        $provider = $this->providerCompany() ?? throw new \DomainException('Société prestataire non définie (réglages des sites).');
        $client = Company::findOrFail($r->company_id);
        $tax = self::settings()['tax_rate'];
        $lines = $lines ?: self::estimate((array) $r->needs)['lines'];
        $lines = array_values(array_filter(array_map(fn ($l) => ['description' => Str::limit(trim((string) ($l['description'] ?? '')), 250, ''), 'quantity' => max(0.01, (float) ($l['quantity'] ?? 1)),
            'unit_price' => max(0, (float) ($l['unit_price'] ?? 0))], $lines), fn ($l) => $l['description'] !== ''));
        if (! $lines) {
            throw new \DomainException('Ajoutez au moins une ligne au devis.');
        }
        $prev = app()->bound('tenant') ? app('tenant') : null;
        app()->instance('tenant', $provider);
        try {
            $email = $client->email ? mb_strtolower($client->email) : null;
            $P = \App\Modules\Core\Models\Partner::class;
            $partner = ($email ? $P::whereRaw('lower(email) = ?', [$email])->first() : null) ?? $P::where('name', $client->name)->first()
                ?? $P::create(['name' => $client->name, 'type' => 'customer', 'email' => $client->email, 'phone' => $client->phone, 'address' => $client->address, 'city' => $client->city,
                    'tax_id' => $client->tax_id, 'notes' => 'Client de la plateforme (société n° '.$client->id.')']);
            $id = (string) Str::uuid();
            $validity = (int) config('erp_sites.quote_validity_days', 30);
            $data = ['type' => 'quote', 'partner_id' => $partner->id, 'date' => now()->toDateString(), 'due_date' => now()->addDays($validity)->toDateString(), 'status' => 'validated',
                'reference' => $r->number, 'notes' => trim('Site internet sur mesure « '.$r->site_name."».\n".($note ?? '')),
                'terms' => 'Acompte de 50 % à la commande, solde à la mise en ligne. Devis valable '.$validity.' jours.',
                'lines' => array_map(fn ($l) => $l + ['id' => (string) Str::uuid(), 'tax_rate' => $tax, 'discount_percent' => max(0, min(100, $discountPercent))], $lines)];
            $old = $r->quote['document_id'] ?? null;
            \App\Modules\Ventes\Models\SalesDocument::$systemWrite = true;
            try {
                $res = app(EntityWriter::class)->upsert(\App\Modules\Ventes\Models\SalesDocument::class, $id, $data);
            } finally {
                \App\Modules\Ventes\Models\SalesDocument::$systemWrite = false;
            }
            if ($res['status'] !== EntityWriter::APPLIED) {
                throw new \DomainException('Devis refusé : '.($res['message'] ?? '').' '.json_encode($res['errors'] ?? []));
            }
            $doc = \App\Modules\Ventes\Models\SalesDocument::with('lines')->find($id);
            if ($old && ($o = \App\Modules\Ventes\Models\SalesDocument::find($old)) && $o->status !== 'cancelled') {
                rescue(fn () => app(EntityWriter::class)->upsert(\App\Modules\Ventes\Models\SalesDocument::class, $o->id, ['status' => 'cancelled']), null, false);
            }
        } finally {
            $prev ? app()->instance('tenant', $prev) : app()->forgetInstance('tenant');
        }
        $r->forceFill(['status' => 'quoted', 'admin_note' => $note, 'quote' => [
            'document_id' => $doc->id, 'number' => $doc->number, 'provider' => $provider->name, 'provider_company_id' => $provider->id, 'date' => $doc->date->toDateString(),
            'valid_until' => $doc->due_date?->toDateString(), 'subtotal' => (float) $doc->subtotal, 'tax' => (float) $doc->tax_total, 'total' => (float) $doc->total, 'currency' => $doc->currency,
            'discount_percent' => $discountPercent, 'terms' => $doc->terms,
            'lines' => $doc->lines->map(fn ($l) => ['description' => $l->description, 'quantity' => (float) $l->quantity, 'unit_price' => (float) $l->unit_price, 'total' => round((float) $l->quantity * (float) $l->unit_price * (1 - $discountPercent / 100), 2)])->values()->all(),
        ]])->save();
        $this->notifyClient($r, 'Devis de votre site : '.number_format((float) $doc->total, 0, ',', ' ').' F', $doc->number.' — acceptez-le ou refusez-le depuis le menu « Site web ».');

        return $r->fresh();
    }

    public function decide(SiteRequest $r, bool $accept, ?string $reason = null): SiteRequest
    {
        if ($r->status !== 'quoted') {
            throw new \DomainException('Aucun devis en attente de réponse.');
        }
        if ($accept && ! empty($r->quote['valid_until']) && $r->quote['valid_until'] < now()->toDateString()) {
            throw new \DomainException('Devis expiré : demandez une mise à jour.');
        }
        $r->forceFill(['status' => $accept ? 'accepted' : 'refused', 'decided_at' => now(), 'admin_note' => $accept ? $r->admin_note : trim(($r->admin_note ? $r->admin_note."\n" : '').'Refus du client : '.($reason ?: '—'))])->save();
        $c = Company::find($r->company_id);
        $this->notifyAdmins(($accept ? 'Devis accepté' : 'Devis refusé').' : '.$c->name, ($r->quote['number'] ?? $r->number).' · '.number_format((float) ($r->quote['total'] ?? 0), 0, ',', ' ').' F'.($reason ? ' — '.$reason : ''), $r->id);

        return $r;
    }

    public function requestToApi(SiteRequest $r): array
    {
        $t = Templates::get($r->template);

        return $r->only(['id', 'number', 'type', 'template', 'site_name', 'sector', 'needs', 'estimate', 'status', 'quote', 'admin_note', 'site_id', 'created_at', 'decided_at'])
            + ['template_name' => $t['name'] ?? null, 'estimate_detail' => $r->type === 'custom' ? self::estimate((array) $r->needs) : null];
    }
}
