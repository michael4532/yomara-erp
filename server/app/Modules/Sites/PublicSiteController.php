<?php

namespace App\Modules\Sites;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteOrder;
use App\Modules\Sites\Models\SitePost;
use App\Payments\InvoicePayments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/** Sites vitrines publics : /site/{adresse}/…, sous-domaine de la plateforme ou domaine du client. */
class PublicSiteController extends Controller
{
    public function __construct(private SiteService $svc, private ShopService $shop) {}

    public static function find(string $slug): ?Site
    {
        return Site::withoutGlobalScopes()->where('slug', $slug)->first();
    }

    protected function visible(Site $s, Request $r): bool
    {
        $c = Company::find($s->company_id);
        if (! $c || $c->status === 'suspended' || ! $c->hasModule('sites')) {
            return false;
        }

        return $s->status === 'published' || ($s->status === 'draft' && hash_equals($s->preview_token, (string) $r->query('apercu')));
    }

    /** Routeur commun : adresse /site/{adresse}/… et hôtes (sous-domaine, domaine du client). */
    public function dispatch(Request $r, Site $s, string $base, string $path)
    {
        $path = trim($path, '/');
        if ($path === 'robots.txt') {
            return response("User-agent: *\nAllow: /\nSitemap: ".url($base.'/sitemap.xml')."\n", 200, ['Content-Type' => 'text/plain']);
        }
        abort_unless($this->visible($s, $r), 404);
        $post = $r->isMethod('post');

        return match (true) {
            $path === '' || $path === 'index.html' => $this->render($s, $r, $base),
            $post && $path === 'contact' => $this->contact($r, $s, $base),
            $path === 'boutique' => $this->shopPage($r, $s, $base),
            (bool) preg_match('#^produit/([0-9a-f-]{36})(?:/[a-z0-9-]{0,160})?$#', $path, $m) => $this->productPage($r, $s, $base, $m[1]),
            $post && $path === 'commande' => $this->order($r, $s, $base),
            (bool) preg_match('#^commande/([A-Za-z0-9]{40})$#', $path, $m) => $this->track($r, $s, $base, $m[1]),
            $post && $path === 'panier' => $this->cart($r, $s),
            $path === 'coupon' => $this->coupon($r, $s),
            (bool) preg_match('#^avis/([A-Za-z0-9]{40})$#', $path, $m) => $post ? $this->saveReview($r, $s, $base, $m[1]) : $this->review($r, $s, $base, $m[1]),
            $path === 'blog' => $this->blog($r, $s, $base),
            (bool) preg_match('#^blog/([a-z0-9-]{1,120})$#', $path, $m) => $this->post($r, $s, $base, $m[1]),
            $path === 'sitemap.xml' => $this->sitemap($s, $base),
            default => redirect($base.'/'),
        };
    }

    public function bySlug(Request $r, string $slug, string $path = '')
    {
        $s = self::find($slug);
        abort_unless($s, 404);

        return $this->dispatch($r, $s, '/site/'.$slug, $path);
    }

    protected function data(Site $s, Request $r, string $base, string $page): array
    {
        $preview = $s->status !== 'published';

        return $this->svc->viewData($s, $preview) + ['base' => $base, 'sent' => $r->query('envoye') === '1', 'error' => $r->query('erreur'), 'url' => $this->svc->url($s)]
            + ['action' => $base.'/contact'.($preview ? '?apercu='.$s->preview_token : '')];
    }

    protected function view(string $view, array $data, string $page)
    {
        $data['page'] = $page;

        return response()->view($view, $data);
    }

    public function render(Site $s, Request $r, string $base = '')
    {
        $preview = $s->status !== 'published';
        if (! $preview && ! preg_match('/bot|crawl|spider|preview|curl|wget/i', (string) $r->userAgent())) {
            rescue(fn () => $this->svc->trackVisit($s, $r->headers->get('referer')), null, false);
        }

        return response()->view('sites.site', $this->data($s, $r, $base, 'home'))->header('X-Robots-Tag', $preview ? 'noindex' : 'all');
    }

    protected function back(Site $s, string $to, array $q)
    {
        return redirect($to.'?'.http_build_query($q + ($s->status !== 'published' ? ['apercu' => $s->preview_token] : [])));
    }

    public function contact(Request $r, Site $s, string $base = '')
    {
        $back = fn (array $q) => redirect($base.'/?'.http_build_query($q + ($s->status !== 'published' ? ['apercu' => $s->preview_token] : [])).'#contact');
        if ($r->filled('website')) {
            return $back(['envoye' => 1]); // robot : on fait comme si
        }
        $key = 'site-contact:'.$s->id.':'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return $back(['erreur' => 'Trop de messages envoyés : réessayez dans quelques minutes.']);
        }
        $v = Validator::make($r->all(), ['name' => 'required|string|max:160', 'email' => 'nullable|email|max:190', 'phone' => 'nullable|string|max:40',
            'subject' => 'nullable|string|max:190', 'message' => 'required|string|min:5|max:5000', 'consent' => 'nullable',
            'utm_source' => 'nullable|string|max:150', 'utm_medium' => 'nullable|string|max:150', 'utm_campaign' => 'nullable|string|max:150', 'utm_content' => 'nullable|string|max:150']);
        if ($v->fails() || (! $r->filled('email') && ! $r->filled('phone'))) {
            return $back(['erreur' => $v->fails() ? $v->errors()->first() : 'Indiquez votre e-mail ou votre téléphone pour être recontacté.']);
        }
        RateLimiter::hit($key, 600);
        $this->svc->contact($s, $v->validated() + ['consent' => $r->boolean('consent')]);

        return $back(['envoye' => 1]);
    }

    // ------------------------------------------------------------------ boutique

    public function shopPage(Request $r, Site $s, string $base)
    {
        $set = $this->shop->settings($s);
        abort_unless($set['enabled'], 404);
        $data = $this->data($s, $r, $base, 'shop');
        $qs = $s->status !== 'published' ? '?apercu='.$s->preview_token : '';
        $products = array_map(fn ($p) => $p + ['url' => $this->shop->productUrl($s, $p, $base).$qs], $this->shop->catalog($s));
        $online = $set['payment_online'] && InvoicePayments::gateways($data['company'])->count() > 0;
        $restore = null;
        if ($r->filled('panier')) {
            $cart = \App\Modules\Sites\Models\SiteCart::withoutGlobalScopes()->where('site_id', $s->id)->where('token', (string) $r->query('panier'))->whereNull('order_id')->first();
            $restore = $cart ? ['token' => $cart->token, 'items' => collect($cart->items)->mapWithKeys(fn ($i) => [$i['id'] => $i['quantity']])->all(), 'name' => $cart->name, 'phone' => $cart->phone, 'email' => $cart->email] : null;
        }
        $ratings = $set['reviews_enabled'] ? $this->shop->ratings($s) : ['count' => 0, 'average' => null, 'products' => []];
        $hasCoupons = \App\Modules\Sites\Models\SiteCoupon::withoutGlobalScopes()->where('site_id', $s->id)->where('active', true)->exists();

        return $this->view('sites.shop', $data + ['buyables' => ShopService::buyables($products), 'restore' => $restore, 'ratings' => $ratings, 'hasCoupons' => $hasCoupons, 'couponUrl' => $base.'/coupon', 'cartUrl' => $base.'/panier', 'catalog' => $products, 'shop' => $set, 'online' => $online,
            'categories' => collect($products)->pluck('category')->filter()->unique()->sort()->values()->all(), 'orderAction' => $base.'/commande'.($s->status !== 'published' ? '?apercu='.$s->preview_token : '')], 'shop');
    }

    public function productPage(Request $r, Site $s, string $base, string $id)
    {
        $set = $this->shop->settings($s);
        abort_unless($set['enabled'], 404);
        $pp = $this->shop->productPage($s, $id);
        abort_unless($pp, 404);
        $qs = $s->status !== 'published' ? '?apercu='.$s->preview_token : '';
        $data = $this->data($s, $r, $base, 'product');
        $online = $set['payment_online'] && InvoicePayments::gateways($data['company'])->count() > 0;
        $hasCoupons = \App\Modules\Sites\Models\SiteCoupon::withoutGlobalScopes()->where('site_id', $s->id)->where('active', true)->exists();
        $pp['related'] = array_map(fn ($p) => $p + ['url' => $this->shop->productUrl($s, $p, $base).$qs], $pp['related']);
        $ratings = $set['reviews_enabled'] ? $this->shop->ratings($s) : ['count' => 0, 'average' => null, 'products' => []];

        return $this->view('sites.product', $pp + $data + ['restore' => null, 'ratings' => $ratings, 'hasCoupons' => $hasCoupons, 'couponUrl' => $base.'/coupon', 'cartUrl' => $base.'/panier',
            'shop' => $set, 'online' => $online, 'canonical' => $this->shop->productUrl($s, $pp['product']), 'orderAction' => $base.'/commande'.$qs], 'shop')
            ->header('X-Robots-Tag', $qs ? 'noindex' : 'all');
    }

    public function order(Request $r, Site $s, string $base)
    {
        $shopUrl = $base.'/boutique';
        if ($r->filled('website')) {
            return $this->back($s, $shopUrl, ['erreur' => 'Commande refusée.']);
        }
        $key = 'site-order:'.$s->id.':'.$r->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            return $this->back($s, $shopUrl, ['erreur' => 'Trop de commandes : réessayez dans quelques minutes.']);
        }
        $v = Validator::make($r->all(), ['name' => 'required|string|max:160', 'phone' => 'required|string|min:8|max:40', 'email' => 'nullable|email|max:190', 'address' => 'nullable|string|max:300',
            'delivery' => 'required|string|max:120', 'payment' => 'required|in:online,cod,momo', 'note' => 'nullable|string|max:1000', 'cart' => 'required|string|max:20000', 'coupon' => 'nullable|string|max:40', 'cart_token' => 'nullable|string|max:40',
            'utm_source' => 'nullable|string|max:150', 'utm_medium' => 'nullable|string|max:150', 'utm_campaign' => 'nullable|string|max:150', 'fbc' => 'nullable|string|max:255'],
            ['name.required' => 'Indiquez votre nom.', 'phone.required' => 'Indiquez votre téléphone.', 'cart.required' => 'Votre panier est vide.']);
        if ($v->fails()) {
            return $this->back($s, $shopUrl, ['erreur' => $v->errors()->first()]);
        }
        $items = json_decode((string) $r->input('cart'), true);
        RateLimiter::hit($key, 600);
        try {
            $o = $this->shop->placeOrder($s, $v->validated() + ['items' => is_array($items) ? $items : []]);
        } catch (\DomainException $e) {
            return $this->back($s, $shopUrl, ['erreur' => $e->getMessage()]);
        }
        rescue(function () use ($s, $o, $r) {
            $c = \App\Models\Company::find($s->company_id);
            if ($c?->hasModule('acquisition')) {
                app(\App\Modules\Acquisition\AdPlatforms::class)->metaConversion($c, 'Purchase', ['email' => $o->email, 'phone' => $o->phone, 'fbc' => $r->input('fbc') ?: $r->cookie('_fbc'), 'fbp' => $r->cookie('_fbp'),
                    'ip' => $r->ip(), 'ua' => $r->userAgent()], (float) $o->total, 'order-'.$o->number, 'website', $this->svc->url($s));
            }
        }, null, false);
        $pay = $this->shop->payUrl($o);

        return $pay ? redirect($pay) : $this->back($s, $base.'/commande/'.$o->token, ['nouveau' => 1]);
    }

    public function track(Request $r, Site $s, string $base, string $token)
    {
        $o = SiteOrder::withoutGlobalScopes()->where('site_id', $s->id)->where('token', $token)->first();
        abort_unless($o, 404);

        return $this->view('sites.order', $this->data($s, $r, $base, 'order') + ['o' => $o, 'shop' => $this->shop->settings($s), 'payUrl' => $this->shop->payUrl($o),
            'isNew' => $r->query('nouveau') === '1', 'statuses' => ShopService::STATUSES, 'modes' => ShopService::PAYMENT_MODES,
            'trackEvents' => $r->query('nouveau') === '1' ? ['purchase' => $o->number, 'value' => (float) $o->total, 'currency' => \App\Models\Company::find($s->company_id)?->currency ?: 'XOF'] : []], 'order')->header('X-Robots-Tag', 'noindex');
    }

    /** Panier enregistré pour la relance (appel du navigateur dès la saisie du téléphone ou de l'e-mail). */
    public function cart(Request $r, Site $s)
    {
        if (! $this->shop->settings($s)['enabled'] || RateLimiter::tooManyAttempts('site-cart:'.$r->ip(), 30)) {
            return response()->json(['ok' => false], 429);
        }
        RateLimiter::hit('site-cart:'.$r->ip(), 600);
        try {
            // champs texte reçus sous forme de liste (robot) : ignorés, pas d'erreur serveur
            $c = $this->shop->saveCart($s, array_filter($r->only(['token', 'name', 'phone', 'email', 'items']), fn ($v, $k) => $k === 'items' || $v === null || is_string($v), ARRAY_FILTER_USE_BOTH));
        } catch (\DomainException) {
            return response()->json(['ok' => false], 422);
        }

        // Panier retrouvé par le téléphone ou l'e-mail (sans son jeton) : mis à jour, mais son jeton n'est pas divulgué
        // (il permettrait de lire le nom, le téléphone et l'e-mail du client à partir de son seul numéro).
        $own = $c->wasRecentlyCreated || (is_string($r->input('token')) && hash_equals($c->token, $r->input('token')));

        return response()->json(['ok' => true, 'token' => $own ? $c->token : null]);
    }

    public function coupon(Request $r, Site $s)
    {
        if (RateLimiter::tooManyAttempts('site-coupon:'.$r->ip(), 20)) {
            return response()->json(['ok' => false, 'message' => 'Trop d\'essais : réessayez dans quelques minutes.'], 429);
        }
        RateLimiter::hit('site-coupon:'.$r->ip(), 600);
        try {
            [$cp, $discount, $ship] = $this->shop->checkCoupon($s, (string) $r->query('code'), (float) $r->query('total'), (string) $r->query('phone', ''), $r->query('email'));
        } catch (\DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'code' => $cp->code, 'discount' => $discount, 'free_shipping' => $ship,
            'label' => $ship ? 'Livraison offerte' : ($cp->type === 'percent' ? '-'.rtrim(rtrim(number_format($cp->value, 2, ',', ''), '0'), ',').' %' : '-'.number_format($discount, 0, ',', ' ').' F')]);
    }

    // ------------------------------------------------------------------ avis vérifiés

    protected function orderFor(Site $s, string $token): SiteOrder
    {
        $o = SiteOrder::withoutGlobalScopes()->where('site_id', $s->id)->where('token', $token)->first();
        abort_unless($o, 404);

        return $o;
    }

    public function review(Request $r, Site $s, string $base, string $token)
    {
        $o = $this->orderFor($s, $token);
        abort_unless($this->shop->settings($s)['reviews_enabled'], 404);
        $done = \App\Modules\Sites\Models\SiteReview::withoutGlobalScopes()->where('order_id', $o->id)->first();

        return $this->view('sites.review', $this->data($s, $r, $base, 'review') + ['o' => $o, 'done' => $done, 'note' => (int) $r->query('note'), 'saved' => $r->query('merci') === '1'], 'review')->header('X-Robots-Tag', 'noindex');
    }

    public function saveReview(Request $r, Site $s, string $base, string $token)
    {
        $o = $this->orderFor($s, $token);
        $to = $base.'/avis/'.$token;
        if ($r->filled('website')) {
            return redirect($to.'?merci=1');
        }
        $v = Validator::make($r->all(), ['rating' => 'required|integer|min:1|max:5', 'comment' => 'nullable|string|max:2000', 'name' => 'nullable|string|max:160', 'products' => 'nullable|array'],
            ['rating.required' => 'Choisissez une note de 1 à 5 étoiles.']);
        if ($v->fails()) {
            return redirect($to.'?'.http_build_query(['erreur' => $v->errors()->first()]));
        }
        try {
            $this->shop->saveReview($s, $o, $v->validated());
        } catch (\DomainException $e) {
            return redirect($to.'?'.http_build_query(['erreur' => $e->getMessage()]));
        }

        return redirect($to.'?merci=1');
    }

    // ------------------------------------------------------------------ blog

    public function blog(Request $r, Site $s, string $base)
    {
        $posts = SitePost::withoutGlobalScopes()->where('site_id', $s->id)->where('published', true)->orderByDesc('published_at')->paginate(12);
        abort_if($posts->total() === 0 && $s->status === 'published', 404);

        return $this->view('sites.blog', $this->data($s, $r, $base, 'blog') + ['list' => $posts], 'blog');
    }

    public function post(Request $r, Site $s, string $base, string $slug)
    {
        $p = SitePost::withoutGlobalScopes()->where('site_id', $s->id)->where('slug', $slug)->first();
        abort_unless($p && ($p->published || $s->status !== 'published' || hash_equals($s->preview_token, (string) $r->query('apercu'))), 404);
        if ($p->published && ! preg_match('/bot|crawl|spider|preview/i', (string) $r->userAgent())) {
            SitePost::withoutGlobalScopes()->whereKey($p->id)->increment('views');
        }
        $others = SitePost::withoutGlobalScopes()->where('site_id', $s->id)->where('published', true)->where('id', '!=', $p->id)->orderByDesc('published_at')->limit(3)->get();

        return $this->view('sites.post', $this->data($s, $r, $base, 'post') + ['p' => $p, 'html' => ShopService::render($p->body), 'cover' => SiteService::img($p->cover_path), 'others' => $others], 'post');
    }

    public function sitemap(Site $s, string $base)
    {
        $root = rtrim($this->svc->url($s), '/');
        $urls = [$root.'/'];
        if ($this->shop->settings($s)['enabled']) {
            $urls[] = $root.'/boutique';
            foreach ($this->shop->catalog($s) as $p) {
                $urls[] = $this->shop->productUrl($s, $p);
            }
        }
        foreach (SitePost::withoutGlobalScopes()->where('site_id', $s->id)->where('published', true)->pluck('slug') as $slug) {
            $urls[] = $root.'/blog/'.$slug;
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', array_map(fn ($u) => '<url><loc>'.e($u).'</loc></url>', $urls)).'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml']);
    }

    /** Aperçu d'un modèle de la galerie avec les données de la société (lien signé, valable quelques heures). */
    public function template(Request $r, string $key)
    {
        abort_unless($r->hasValidSignature(), 403);
        $t = Templates::get($key);
        $c = Company::find((int) $r->query('c'));
        abort_unless($t && $c, 404);
        $s = new Site(['company_id' => $c->id, 'name' => $c->name, 'slug' => 'apercu', 'template' => $key, 'status' => 'draft', 'theme' => [], 'content' => [], 'sections' => $t['sections'], 'preview_token' => '']);
        $data = $this->svc->viewData($s, true) + ['base' => '', 'sent' => false, 'error' => null, 'url' => '#', 'template_preview' => true];
        $data['action'] = '#';

        return response()->view('sites.site', $data)->header('X-Robots-Tag', 'noindex');
    }
}
