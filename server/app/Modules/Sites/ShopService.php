<?php

namespace App\Modules\Sites;

use App\Messaging\Message;
use App\Messaging\MessagingService;
use App\Models\AppNotification;
use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteOrder;
use App\Modules\Sites\Models\SitePost;
use App\Modules\Sites\Models\SiteProductPage;
use App\Modules\Ventes\Models\SalesDocument;
use App\Payments\InvoicePayments;
use App\Services\EntityWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Boutique en ligne et blog des sites vitrines, reliés aux Ventes, au stock et aux paiements de l'ERP. */
class ShopService
{
    public const STATUSES = ['new' => 'Nouvelle', 'confirmed' => 'Confirmée', 'preparing' => 'En préparation', 'shipped' => 'Expédiée', 'delivered' => 'Livrée', 'cancelled' => 'Annulée'];

    public const PAYMENT_MODES = ['online' => 'Paiement en ligne (Mobile Money, carte)', 'cod' => 'Paiement à la livraison', 'momo' => 'Transfert Mobile Money'];

    // ------------------------------------------------------------------ réglages

    public static function defaults(): array
    {
        return ['enabled' => false, 'pickup' => true, 'pickup_address' => null, 'zones' => [['name' => 'Livraison à Abidjan', 'fee' => 1500]], 'free_from' => null, 'min_order' => 0,
            'payment_online' => true, 'payment_cod' => true, 'payment_momo' => false, 'momo_instructions' => null, 'note' => null, 'categories' => null, 'hide_out_of_stock' => false,
            'reviews_enabled' => true, 'reviews_auto_publish' => true, 'review_delay_days' => 1, 'cart_reminders' => true, 'cart_delay_hours' => 2];
    }

    public function settings(Site $s): array
    {
        return array_replace(self::defaults(), (array) (((array) $s->content)['shop'] ?? []));
    }

    public function saveSettings(Site $s, array $d): array
    {
        $cur = $this->settings($s);
        foreach (['review_delay_days' => [0, 30], 'cart_delay_hours' => [1, 72]] as $k => [$lo, $hi]) {
            if (array_key_exists($k, $d)) {
                $cur[$k] = max($lo, min($hi, (int) $d[$k]));
            }
        }
        foreach (['enabled', 'pickup', 'payment_online', 'payment_cod', 'payment_momo', 'hide_out_of_stock', 'reviews_enabled', 'reviews_auto_publish', 'cart_reminders'] as $k) {
            if (array_key_exists($k, $d)) {
                $cur[$k] = (bool) $d[$k];
            }
        }
        foreach (['pickup_address' => 200, 'momo_instructions' => 400, 'note' => 400] as $k => $max) {
            if (array_key_exists($k, $d)) {
                $cur[$k] = Str::limit(trim((string) $d[$k]), $max, '') ?: null;
            }
        }
        foreach (['free_from', 'min_order'] as $k) {
            if (array_key_exists($k, $d)) {
                $cur[$k] = $d[$k] === null || $d[$k] === '' ? null : max(0, (float) $d[$k]);
            }
        }
        if (array_key_exists('zones', $d)) {
            $cur['zones'] = array_values(array_slice(array_filter(array_map(fn ($z) => ['name' => Str::limit(trim((string) ($z['name'] ?? '')), 80, ''), 'fee' => max(0, (float) ($z['fee'] ?? 0))], (array) $d['zones']), fn ($z) => $z['name'] !== ''), 0, 20));
        }
        if (array_key_exists('categories', $d)) {
            $cur['categories'] = array_values(array_filter((array) $d['categories'], fn ($x) => is_string($x) && Str::isUuid($x))) ?: null;
        }
        if ($cur['enabled'] && ! $cur['payment_online'] && ! $cur['payment_cod'] && ! $cur['payment_momo']) {
            throw new \DomainException('Activez au moins un moyen de paiement.');
        }
        if ($cur['enabled'] && ! $cur['pickup'] && ! $cur['zones']) {
            throw new \DomainException('Proposez le retrait en boutique ou au moins une zone de livraison.');
        }
        $ct = (array) $s->content;
        $ct['shop'] = $cur;
        $s->content = $ct;
        $s->save();

        return $cur;
    }

    public static function ttc(float $ht, float $rate): float
    {
        return round($ht * (1 + $rate / 100));
    }

    protected function stockOf(Company $c, array $ids): array
    {
        if (! $c->hasModule('stock') || ! $ids) {
            return [];
        }

        $out = DB::table('stock_moves')->where('company_id', $c->id)->whereIn('product_id', $ids)->whereNull('deleted_at')->selectRaw('product_id, sum(quantity) q')->groupBy('product_id')->pluck('q', 'product_id')->map(fn ($q) => (float) $q)->all();
        // Module Boutique en ligne : le stock promis aux commandes en cours (non encore facturées) n'est plus disponible
        if ($c->hasModule('ecommerce')) {
            foreach (app(\App\Modules\Ecommerce\EcommerceService::class)->reserved($c, $ids) as $pid => $q) {
                $out[$pid] = ($out[$pid] ?? 0) - $q;
            }
        }

        return $out;
    }

    /** Catalogue publié : produits actifs avec prix TTC, catégorie, image et disponibilité. */
    /**
     * Catalogue de la boutique : une carte par produit simple ou par modèle à variantes (prix « à partir de »).
     * Chaque carte porte ses variantes achetables ; les variantes n'ont pas de carte à elles.
     */
    public function catalog(Site $s, bool $all = false): array
    {
        $c = Company::find($s->company_id);
        $set = $this->settings($s);
        $q = Product::where('active', true)->whereNull('parent_id')->with('category')
            ->where(fn ($w) => $w->where('sale_price', '>', 0)->orWhere('is_template', true));
        if ($set['categories'] && ! $all) {
            $q->whereIn('category_id', $set['categories']);
        }
        $rows = $q->orderBy('name')->limit(500)->get();
        $tplIds = $rows->where('is_template', true)->pluck('id')->all();
        $vars = $tplIds ? Product::where('active', true)->whereIn('parent_id', $tplIds)->where('sale_price', '>', 0)->orderBy('name')->get()->groupBy('parent_id') : collect();
        $tracked = $rows->where('track_stock', true)->where('is_template', false)->pluck('id')
            ->merge($vars->flatten()->where('track_stock', true)->pluck('id'))->all();
        $stock = $this->stockOf($c, $tracked);
        $pages = SiteProductPage::withoutGlobalScopes()->where('site_id', $s->id)->get()->keyBy('product_id');
        $hasStock = $c->hasModule('stock');
        $avail = fn (Product $p) => $p->track_stock && $hasStock ? max(0, $stock[$p->id] ?? 0) : null;
        $out = [];
        foreach ($rows as $p) {
            $page = $pages[$p->id] ?? null;
            $variants = [];
            if ($p->is_template) {
                foreach ($vars[$p->id] ?? [] as $v) {
                    $variants[] = ['id' => $v->id, 'name' => $v->name, 'values' => (array) $v->variant_values, 'label' => implode(' / ', (array) $v->variant_values),
                        'price' => self::ttc((float) $v->sale_price, (float) $v->tax_rate), 'stock' => $avail($v), 'sku' => $v->sku, 'image' => self::imageOf($v)];
                }
                if (! $variants) {
                    continue; // modèle sans variante vendable
                }
                $stocks = array_column($variants, 'stock');
                $left = in_array(null, $stocks, true) ? null : array_sum($stocks);
                $prices = array_column($variants, 'price');
                $price = min($prices);
            } else {
                $left = $avail($p);
                $price = self::ttc((float) $p->sale_price, (float) $p->tax_rate);
                $prices = [$price];
            }
            if ($set['hide_out_of_stock'] && $left !== null && $left <= 0 && ! $all) {
                continue;
            }
            $photos = $this->photos($page);
            $img = $photos[0]['url'] ?? self::imageOf($p) ?? ($variants ? collect($variants)->pluck('image')->filter()->first() : null);
            $compare = $page?->compare_at && $page->compare_at > $price ? (float) $page->compare_at : null;
            $out[] = ['id' => $p->id, 'name' => $p->name, 'description' => Str::limit((string) $p->description, 220), 'price' => $price, 'price_from' => count(array_unique($prices)) > 1,
                'category' => $p->category?->name, 'category_id' => $p->category_id, 'image' => $img, 'unit' => $p->unit, 'stock' => $left,
                'is_template' => (bool) $p->is_template, 'attributes' => $p->is_template ? array_values(array_filter((array) $p->variant_attributes, fn ($a) => ! empty($a['name']))) : [],
                'variants' => $variants, 'compare_at' => $compare, 'badge' => $page?->badge ?: ($compare ? '−'.round(100 - $price * 100 / $compare).' %' : null),
                'slug' => Str::slug($p->name) ?: 'produit'];
        }

        return $out;
    }

    /** Articles achetables (produits simples et variantes) indexés par identifiant, pour le panier. */
    public static function buyables(array $catalog): array
    {
        $out = [];
        foreach ($catalog as $p) {
            if ($p['is_template']) {
                foreach ($p['variants'] as $v) {
                    $out[$v['id']] = ['n' => $v['name'], 'p' => $v['price'], 's' => $v['stock']];
                }
            } else {
                $out[$p['id']] = ['n' => $p['name'], 'p' => $p['price'], 's' => $p['stock']];
            }
        }

        return $out;
    }

    public static function imageOf(Product $p): ?string
    {
        return is_string($p->image) && (str_starts_with($p->image, 'http') || str_starts_with($p->image, 'data:image')) ? $p->image : null;
    }

    public function productUrl(Site $s, array $card, string $base = ''): string
    {
        return ($base ?: rtrim(app(SiteService::class)->url($s), '/')).'/produit/'.$card['id'].'/'.$card['slug'];
    }

    // ------------------------------------------------------------------ fiches produits

    protected function photos(?SiteProductPage $page): array
    {
        return collect((array) $page?->photos)->filter(fn ($p) => ! empty($p['path']))
            ->map(fn ($p) => ['path' => $p['path'], 'url' => SiteService::img($p['path']), 'tag' => $p['tag'] ?? null])->values()->all();
    }

    /** Fiche produit détaillée : galerie, description longue, variantes, avis et produits liés. */
    public function productPage(Site $s, string $id): ?array
    {
        $catalog = $this->catalog($s);
        $card = collect($catalog)->firstWhere('id', $id);
        $variant = null;
        if (! $card) { // lien vers une variante : on ouvre son modèle, variante présélectionnée
            foreach ($catalog as $c) {
                if ($v = collect($c['variants'])->firstWhere('id', $id)) {
                    [$card, $variant] = [$c, $v['id']];
                    break;
                }
            }
        }
        if (! $card) {
            return null;
        }
        $page = SiteProductPage::withoutGlobalScopes()->where('site_id', $s->id)->where('product_id', $card['id'])->first();
        $p = Product::find($card['id']);
        $photos = $this->photos($page);
        if (! $photos) {
            foreach (array_filter(array_merge([self::imageOf($p)], array_column($card['variants'], 'image'))) as $u) {
                $photos[] = ['path' => null, 'url' => $u, 'tag' => null];
            }
            $photos = collect($photos)->unique('url')->values()->all();
        }
        // avis : notes de la fiche et de toutes ses variantes
        $ids = array_merge([$card['id']], array_column($card['variants'], 'id'));
        $reviews = [];
        $notes = [];
        if ($this->settings($s)['reviews_enabled']) {
            foreach (\App\Modules\Sites\Models\SiteReview::withoutGlobalScopes()->where('site_id', $s->id)->where('status', 'published')->orderByDesc('id')->limit(400)->get() as $r) {
                $mine = array_intersect_key((array) $r->products, array_flip($ids));
                if (! $mine) {
                    continue;
                }
                array_push($notes, ...array_values($mine));
                $reviews[] = ['name' => $r->name, 'rating' => (int) round(array_sum($mine) / count($mine)), 'comment' => $r->comment, 'reply' => $r->reply, 'date' => $r->created_at?->toDateString()];
            }
        }
        // produits liés : choix manuel, sinon même catégorie, sinon les plus récents
        $others = collect($catalog)->where('id', '!=', $card['id']);
        $manual = array_values(array_filter((array) $page?->related));
        $related = $others->filter(fn ($o) => in_array($o['id'], $manual, true))->sortBy(fn ($o) => array_search($o['id'], $manual))->values();
        if ($related->count() < 4) {
            $fill = $others->whereNotIn('id', $related->pluck('id'))->sortByDesc(fn ($o) => $card['category_id'] && $o['category_id'] === $card['category_id'] ? 1 : 0);
            $related = $related->merge($fill->take(4 - $related->count()));
        }
        $desc = trim((string) ($page?->description ?: $p?->description));

        return ['product' => $card, 'variant' => $variant, 'photos' => $photos, 'description' => $desc ? self::render($desc) : null,
            'reviews' => array_slice($reviews, 0, 30), 'rating' => $notes ? ['average' => round(array_sum($notes) / count($notes), 1), 'count' => count($notes)] : null,
            'related' => $related->take(4)->values()->all(), 'buyables' => self::buyables($catalog)];
    }

    public function pageOf(Site $s, string $productId): SiteProductPage
    {
        return SiteProductPage::firstOrNew(['site_id' => $s->id, 'product_id' => $productId], ['company_id' => $s->company_id]);
    }

    public function savePage(Site $s, Product $p, array $d): SiteProductPage
    {
        if ($p->parent_id) {
            throw new \DomainException('Les variantes partagent la fiche de leur modèle.');
        }
        $pg = $this->pageOf($s, $p->id);
        if (array_key_exists('description', $d)) {
            $pg->description = trim((string) $d['description']) ?: null;
        }
        if (array_key_exists('badge', $d)) {
            $pg->badge = Str::limit(trim((string) $d['badge']), 28, '') ?: null;
        }
        if (array_key_exists('compare_at', $d)) {
            $pg->compare_at = (float) $d['compare_at'] > 0 ? round((float) $d['compare_at']) : null;
        }
        if (array_key_exists('related', $d)) {
            $ids = array_values(array_unique(array_filter((array) $d['related'], fn ($i) => Str::isUuid((string) $i) && $i !== $p->id)));
            $pg->related = Product::whereIn('id', $ids)->whereNull('parent_id')->pluck('id')->intersect($ids)->sortBy(fn ($i) => array_search($i, $ids))->values()->all() ?: null;
        }
        if (array_key_exists('photo_tags', $d)) {
            $tags = (array) $d['photo_tags'];
            $pg->photos = collect((array) $pg->photos)->map(fn ($ph) => ['path' => $ph['path'], 'tag' => array_key_exists($ph['path'], $tags) ? (trim((string) $tags[$ph['path']]) ?: null) : ($ph['tag'] ?? null)])->values()->all();
        }
        if (array_key_exists('photo_order', $d)) {
            $order = array_values((array) $d['photo_order']);
            $pg->photos = collect((array) $pg->photos)->sortBy(fn ($ph) => ($i = array_search($ph['path'], $order, true)) === false ? 999 : $i)->values()->all();
        }
        $pg->save();

        return $pg;
    }

    public function addPhoto(Site $s, Product $p, UploadedFile $f, ?string $tag = null): SiteProductPage
    {
        $ext = strtolower($f->getClientOriginalExtension());
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) || $f->getSize() > 4 * 1024 * 1024) {
            throw new \DomainException('Image PNG, JPG ou WEBP de 4 Mo au plus.');
        }
        if ($p->parent_id) {
            throw new \DomainException('Ajoutez les photos sur la fiche du modèle.');
        }
        $pg = $this->pageOf($s, $p->id);
        if (count((array) $pg->photos) >= 10) {
            throw new \DomainException('10 photos au plus par produit.');
        }
        $path = 'sites/'.$s->company_id.'/'.$s->id.'/produits/'.Str::random(10).'.'.$ext;
        Storage::disk('public')->put($path, (string) file_get_contents($f->getRealPath()));
        $pg->photos = array_merge((array) $pg->photos, [['path' => $path, 'tag' => trim((string) $tag) ?: null]]);
        $pg->save();

        return $pg;
    }

    public function removePhoto(Site $s, Product $p, string $path): SiteProductPage
    {
        $pg = $this->pageOf($s, $p->id);
        $pg->photos = array_values(array_filter((array) $pg->photos, fn ($ph) => $ph['path'] !== $path)) ?: null;
        $pg->save();
        if (str_starts_with($path, 'sites/'.$s->company_id.'/'.$s->id.'/produits/')) {
            Storage::disk('public')->delete($path);
        }

        return $pg;
    }

    /** Liste des fiches pour l'ERP (produits simples et modèles, y compris ceux masqués de la boutique). */
    public function pagesToApi(Site $s): array
    {
        $catalog = collect($this->catalog($s, true));
        $visible = collect($this->catalog($s))->pluck('id')->flip();
        $pages = SiteProductPage::where('site_id', $s->id)->get()->keyBy('product_id');
        $ratings = $this->ratings($s)['products'];

        return $catalog->sortBy(fn ($c) => (isset($visible[$c['id']]) ? '0' : '1').mb_strtolower($c['name']))->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'category' => $c['category'], 'price' => $c['price'], 'price_from' => $c['price_from'],
            'is_template' => $c['is_template'], 'variants' => count($c['variants']), 'stock' => $c['stock'], 'image' => $c['image'], 'visible' => isset($visible[$c['id']]),
            'url' => $this->productUrl($s, $c), 'rating' => $ratings[$c['id']] ?? null,
            'attribute_values' => collect($c['attributes'])->pluck('values')->flatten()->unique()->values()->all(),
            'page' => ['description' => $pages[$c['id']]->description ?? null, 'badge' => $pages[$c['id']]->badge ?? null, 'compare_at' => $pages[$c['id']]->compare_at ?? null,
                'related' => $pages[$c['id']]->related ?? [], 'photos' => $this->photos($pages[$c['id']] ?? null)],
        ])->values()->all();
    }

    // ------------------------------------------------------------------ commandes

    public function placeOrder(Site $s, array $d): SiteOrder
    {
        $c = Company::find($s->company_id);
        app()->instance('tenant', $c);
        $set = $this->settings($s);
        if ((! $set['enabled'] || $s->status !== 'published') && empty($d['_manual'])) {
            throw new \DomainException('La boutique en ligne n\'est pas ouverte.');
        }
        $items = [];
        foreach (array_slice((array) ($d['items'] ?? []), 0, 60) as $it) {
            $qty = (int) ($it['quantity'] ?? 0);
            if ($qty > 0 && Str::isUuid((string) ($it['id'] ?? ''))) {
                $items[$it['id']] = min(999, ($items[$it['id']] ?? 0) + $qty);
            }
        }
        if (! $items) {
            throw new \DomainException('Votre panier est vide.');
        }
        $products = Product::whereIn('id', array_keys($items))->where('active', true)->where('is_template', false)->where('sale_price', '>', 0)->get()->keyBy('id');
        if ($products->count() !== count($items)) {
            throw new \DomainException('Un article de votre panier n\'est plus disponible : actualisez la page.');
        }
        $stock = $this->stockOf($c, $products->where('track_stock', true)->pluck('id')->all());
        $lines = [];
        $snap = [];
        foreach ($items as $id => $qty) {
            $p = $products[$id];
            if ($p->track_stock && $c->hasModule('stock') && $qty > max(0, $stock[$id] ?? 0)) {
                $left = max(0, (int) floor($stock[$id] ?? 0));
                throw new \DomainException('« '.$p->name.' » : '.($left ? 'plus que '.$left.' en stock.' : 'rupture de stock.'));
            }
            $lines[] = ['id' => (string) Str::uuid(), 'product_id' => $p->id, 'description' => $p->name, 'quantity' => $qty, 'unit_price' => (float) $p->sale_price, 'tax_rate' => (float) $p->tax_rate, 'discount_percent' => 0];
            $snap[] = ['product_id' => $p->id, 'name' => $p->name, 'quantity' => $qty, 'unit_price' => self::ttc((float) $p->sale_price, (float) $p->tax_rate), 'total' => $qty * self::ttc((float) $p->sale_price, (float) $p->tax_rate)];
        }
        $sub = array_sum(array_column($snap, 'total'));
        if ($set['min_order'] && $sub < $set['min_order'] && empty($d['_manual'])) {
            throw new \DomainException('Commande minimum : '.number_format($set['min_order'], 0, ',', ' ').' F.');
        }
        $mode = (string) ($d['delivery'] ?? '');
        if ($mode === 'pickup' && $set['pickup']) {
            $fee = 0.0;
            $deliveryLabel = 'Retrait en boutique';
        } else {
            $zone = collect($set['zones'])->firstWhere('name', $mode) ?? throw new \DomainException('Choisissez un mode de livraison.');
            $fee = $set['free_from'] && $sub >= $set['free_from'] ? 0.0 : (float) $zone['fee'];
            $deliveryLabel = $zone['name'];
            if (blank($d['address'] ?? null)) {
                throw new \DomainException('Indiquez l\'adresse de livraison.');
            }
        }
        // Code promo : remise sur les articles (ou livraison offerte), appliquée en pourcentage sur les lignes du document de vente
        $coupon = null;
        $discount = 0.0;
        if (filled($d['coupon'] ?? null)) {
            [$coupon, $discount, $freeShip] = $this->checkCoupon($s, (string) $d['coupon'], $sub, (string) ($d['phone'] ?? ''), $d['email'] ?? null);
            if ($freeShip && $fee > 0) {
                $discount = 0.0;
                $fee = 0.0;
            }
        }
        if ($discount > 0) {
            $pct = round(min(100, $discount * 100 / $sub), 2);
            foreach ($lines as &$l) {
                $l['discount_percent'] = $pct;
            }
            unset($l);
        }
        $pay = (string) ($d['payment'] ?? '');
        if (! in_array($pay, array_keys(self::PAYMENT_MODES), true) || ! $set['payment_'.$pay]) {
            throw new \DomainException('Choisissez un moyen de paiement.');
        }
        if ($pay === 'online' && ! InvoicePayments::gateways($c)->count()) {
            $pay = $set['payment_cod'] ? 'cod' : throw new \DomainException('Le paiement en ligne n\'est pas disponible pour le moment.');
        }
        if ($fee > 0) {
            $lines[] = ['id' => (string) Str::uuid(), 'description' => 'Livraison — '.$deliveryLabel, 'quantity' => 1, 'unit_price' => $fee, 'tax_rate' => 0, 'discount_percent' => 0];
        }

        return DB::transaction(function () use ($s, $c, $d, $lines, $snap, $sub, $fee, $deliveryLabel, $pay, $coupon) {
            $partner = $this->partner($d);
            $year = now()->year;
            $n = SiteOrder::withoutGlobalScopes()->where('company_id', $c->id)->where('number', 'like', 'WEB-'.$year.'-%')->count() + 1;
            $number = 'WEB-'.$year.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $docId = (string) Str::uuid();
            SalesDocument::$systemWrite = true;
            try {
                $res = app(EntityWriter::class)->upsert(SalesDocument::class, $docId, [
                    'type' => $pay === 'online' ? 'invoice' : 'order', 'partner_id' => $partner->id, 'date' => now()->toDateString(), 'status' => 'validated', 'reference' => $number,
                    'notes' => trim('Commande du site internet '.$number.($coupon ? ' · code promo '.$coupon->code : '').' · '.$deliveryLabel.($d['address'] ?? null ? ' · '.$d['address'] : '').($d['note'] ?? null ? "\n".$d['note'] : '')),
                    'lines' => $lines,
                ]);
            } finally {
                SalesDocument::$systemWrite = false;
            }
            if ($res['status'] !== EntityWriter::APPLIED) {
                throw new \DomainException('Commande refusée : '.($res['message'] ?? ''));
            }
            $doc = SalesDocument::find($docId);
            $o = SiteOrder::create([
                'site_id' => $s->id, 'number' => $number, 'token' => Str::random(40), 'name' => Str::limit(trim($d['name']), 160, ''), 'phone' => Str::limit(trim($d['phone']), 40, ''),
                'email' => $d['email'] ?? null, 'address' => isset($d['address']) ? Str::limit(trim((string) $d['address']), 300, '') : null, 'delivery_mode' => $deliveryLabel, 'delivery_fee' => $fee,
                'payment_mode' => $pay, 'items' => $snap, 'subtotal' => $sub, 'total' => (float) $doc->total, 'document_id' => $doc->id, 'partner_id' => $partner->id,
                'note' => isset($d['note']) ? Str::limit(trim((string) $d['note']), 1000, '') : null,
                'coupon_code' => $coupon?->code, 'discount' => $coupon ? max(0, round($sub + $fee - (float) $doc->total)) : 0,
                'utm_source' => isset($d['utm_source']) ? Str::limit((string) $d['utm_source'], 100, '') : null, 'utm_medium' => isset($d['utm_medium']) ? Str::limit((string) $d['utm_medium'], 100, '') : null,
                'utm_campaign' => isset($d['utm_campaign']) ? Str::limit(Str::slug((string) $d['utm_campaign'], '_'), 150, '') : null,
            ]);
            if ($coupon) {
                \App\Modules\Sites\Models\SiteCoupon::whereKey($coupon->id)->increment('uses');
            }
            $this->linkCarts($s, $o, $d['cart_token'] ?? null);
            $users = User::where('company_id', $c->id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('sites.manage') || $u->hasPermission('ventes.manage'))->pluck('id');
            AppNotification::send($c->id, $users, 'site_order', 'Commande en ligne '.$number.' : '.number_format($o->total, 0, ',', ' ').' F', $o->name.' · '.$deliveryLabel.' · '.self::PAYMENT_MODES[$pay], 'sites', (string) $o->id);
            rescue(fn () => $this->confirmEmail($c, $s, $o), null, false);

            return $o;
        });
    }

    protected function partner(array $d): Partner
    {
        $digits = substr(preg_replace('/\D/', '', (string) $d['phone']), -8);
        $p = null;
        if (! empty($d['email'])) {
            $p = Partner::whereRaw('lower(email) = ?', [mb_strtolower($d['email'])])->first();
        }
        if (! $p && strlen($digits) === 8) {
            $p = Partner::whereNotNull('phone')->where('phone', 'like', '%'.substr($digits, -4))->get()->first(fn ($x) => substr(preg_replace('/\D/', '', (string) $x->phone), -8) === $digits);
        }

        return $p ?? Partner::create(['name' => Str::limit(trim($d['name']), 160, ''), 'type' => 'customer', 'phone' => $d['phone'], 'email' => $d['email'] ?? null, 'address' => $d['address'] ?? null,
            'notes' => 'Client créé par une commande du site internet']);
    }

    public function payUrl(SiteOrder $o): ?string
    {
        if ($o->payment_mode !== 'online' || $o->payment_status === 'paid' || $o->status === 'cancelled') {
            return null;
        }
        $doc = SalesDocument::find($o->document_id);

        return $doc && $doc->type === 'invoice' ? InvoicePayments::url(app(InvoicePayments::class)->link($doc)) : null;
    }

    public function trackUrl(Site $s, SiteOrder $o): string
    {
        return rtrim(app(SiteService::class)->url($s), '/').'/commande/'.$o->token;
    }

    protected function confirmEmail(Company $c, Site $s, SiteOrder $o): void
    {
        if (! $o->email) {
            return;
        }
        $m = app(MessagingService::class);
        if (! ($m->available($c)['email'] ?? null)) {
            return;
        }
        $rows = collect($o->items)->map(fn ($i) => '<tr><td style="padding:6px 0">'.e($i['quantity']).' × '.e($i['name']).'</td><td style="padding:6px 0;text-align:right">'.number_format($i['total'], 0, ',', ' ').' F</td></tr>')->implode('');
        $url = $this->trackUrl($s, $o);
        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;line-height:1.5;color:#1b2333"><h2 style="margin:0 0 6px">Merci pour votre commande</h2><p>Bonjour '.e($o->name).', votre commande <b>'.e($o->number).'</b> chez '.e($c->name).' est bien enregistrée.</p>'
            .'<table style="width:100%;border-collapse:collapse;font-size:14px">'.$rows.($o->delivery_fee > 0 ? '<tr><td>'.e($o->delivery_mode).'</td><td style="text-align:right">'.number_format($o->delivery_fee, 0, ',', ' ').' F</td></tr>' : '')
            .'<tr><td style="padding-top:8px;border-top:1px solid #ddd"><b>Total</b></td><td style="padding-top:8px;border-top:1px solid #ddd;text-align:right"><b>'.number_format($o->total, 0, ',', ' ').' F</b></td></tr></table>'
            .'<p>'.e(self::PAYMENT_MODES[$o->payment_mode]).' · '.e($o->delivery_mode).'</p><p><a href="'.e($url).'" style="display:inline-block;background:#1F6FEB;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Suivre ma commande</a></p></div>';
        $m->send($c, 'email', new Message(to: $o->email, subject: 'Commande '.$o->number.' — '.$c->name, html: $html, text: 'Commande '.$o->number.' enregistrée. Suivi : '.$url, toName: $o->name, replyTo: $c->email), 'boutique');
    }

    /** Facture d'une commande payée en ligne : commande payée et confirmée, équipe prévenue. */
    public function onInvoicePaid(SalesDocument $doc): void
    {
        foreach (SiteOrder::withoutGlobalScopes()->where('company_id', $doc->company_id)->where(fn ($q) => $q->where('document_id', $doc->id)->orWhere('invoice_id', $doc->id))->where('payment_status', 'unpaid')->get() as $o) {
            $o->forceFill(['payment_status' => 'paid', 'status' => $o->status === 'new' ? 'confirmed' : $o->status])->save();
            $users = User::where('company_id', $o->company_id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('sites.manage') || $u->hasPermission('ventes.manage'))->pluck('id');
            AppNotification::send($o->company_id, $users, 'site_order', 'Commande payée en ligne : '.$o->number, $o->name.' · '.number_format($o->total, 0, ',', ' ').' F — à préparer', 'sites', (string) $o->id);
        }
    }

    public function setStatus(SiteOrder $o, string $status, ?string $note = null, bool $paid = false): SiteOrder
    {
        if (! isset(self::STATUSES[$status])) {
            throw new \DomainException('État inconnu.');
        }
        if (in_array($o->status, ['delivered', 'cancelled'], true) && $status !== $o->status) {
            throw new \DomainException('Commande clôturée.');
        }
        if ($status === 'cancelled') {
            if ($o->payment_status === 'paid') {
                throw new \DomainException('Commande déjà payée : faites un avoir dans les Ventes avant de l\'annuler.');
            }
            $doc = SalesDocument::find($o->document_id);
            if ($doc && $doc->status !== 'cancelled' && (float) $doc->amount_paid <= 0) {
                $r = app(EntityWriter::class)->upsert(SalesDocument::class, $doc->id, ['status' => 'cancelled']);
                if ($r['status'] !== EntityWriter::APPLIED) {
                    throw new \DomainException('Annulation du document de vente refusée : '.($r['message'] ?? ''));
                }
            }
        }
        $o->status = $status;
        if ($paid && $o->payment_mode !== 'online') {
            $o->payment_status = 'paid';
        }
        if ($note !== null) {
            $o->staff_note = Str::limit(trim($note), 1000, '') ?: null;
        }
        $o->save();

        return $o;
    }

    public function orderToApi(SiteOrder $o, ?Site $s = null): array
    {
        $s ??= Site::find($o->site_id);
        $doc = $o->document_id ? SalesDocument::withoutGlobalScopes()->find($o->document_id) : null;
        $phone = preg_replace('/\D/', '', $o->phone);
        $wa = strlen($phone) === 10 ? '225'.$phone : $phone;

        return $o->toArray() + ['status_label' => self::STATUSES[$o->status] ?? $o->status, 'payment_label' => self::PAYMENT_MODES[$o->payment_mode] ?? $o->payment_mode,
            'document' => $doc ? ['id' => $doc->id, 'number' => $doc->number, 'type' => $doc->type, 'status' => $doc->status, 'payment_status' => $doc->payment_status] : null,
            'track_url' => $s ? $this->trackUrl($s, $o) : null, 'pay_url' => $this->payUrl($o),
            'whatsapp_url' => $wa ? 'https://wa.me/'.$wa.'?text='.rawurlencode('Bonjour '.$o->name.', votre commande '.$o->number.' : '.mb_strtolower(self::STATUSES[$o->status] ?? '').'.') : null];
    }

    public function stats(Site $s): array
    {
        $q = SiteOrder::where('site_id', $s->id)->where('status', '!=', 'cancelled');
        $month = (clone $q)->where('created_at', '>=', now()->startOfMonth());

        return ['open' => (clone $q)->whereIn('status', ['new', 'confirmed', 'preparing', 'shipped'])->count(), 'new' => (clone $q)->where('status', 'new')->count(),
            'month_count' => (clone $month)->count(), 'month_total' => (float) (clone $month)->sum('total'), 'total' => (float) (clone $q)->sum('total')];
    }


    // ------------------------------------------------------------------ codes promo

    public const COUPON_TYPES = ['percent' => 'Pourcentage', 'amount' => 'Montant fixe', 'shipping' => 'Livraison offerte'];

    public function saveCoupon(Site $s, ?\App\Modules\Sites\Models\SiteCoupon $cp, array $d): \App\Modules\Sites\Models\SiteCoupon
    {
        $cp ??= new \App\Modules\Sites\Models\SiteCoupon(['site_id' => $s->id, 'company_id' => $s->company_id]);
        if (array_key_exists('code', $d) || ! $cp->exists) {
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($d['code'] ?? '')));
            if (strlen($code) < 3) {
                throw new \DomainException('Code de 3 caractères au moins (lettres, chiffres, - ou _).');
            }
            if (\App\Modules\Sites\Models\SiteCoupon::withoutGlobalScopes()->where('site_id', $s->id)->where('code', $code)->where('id', '!=', $cp->id ?? 0)->exists()) {
                throw new \DomainException('Ce code existe déjà.');
            }
            $cp->code = Str::limit($code, 40, '');
        }
        if (array_key_exists('type', $d) || ! $cp->exists) {
            $cp->type = isset(self::COUPON_TYPES[$d['type'] ?? '']) ? $d['type'] : throw new \DomainException('Type de remise inconnu.');
        }
        foreach (['value', 'min_order'] as $k) {
            if (array_key_exists($k, $d)) {
                $cp->{$k} = max(0, (float) $d[$k]);
            }
        }
        if ($cp->type === 'percent' && ($cp->value <= 0 || $cp->value > 100)) {
            throw new \DomainException('Pourcentage entre 1 et 100.');
        }
        if ($cp->type === 'amount' && $cp->value <= 0) {
            throw new \DomainException('Indiquez le montant de la remise.');
        }
        foreach (['starts_on', 'ends_on'] as $k) {
            if (array_key_exists($k, $d)) {
                $cp->{$k} = $d[$k] ? \Illuminate\Support\Carbon::parse($d[$k])->toDateString() : null;
            }
        }
        if ($cp->starts_on && $cp->ends_on && $cp->ends_on->lt($cp->starts_on)) {
            throw new \DomainException('La date de fin précède la date de début.');
        }
        if (array_key_exists('max_uses', $d)) {
            $cp->max_uses = $d['max_uses'] === null || $d['max_uses'] === '' ? null : max(1, (int) $d['max_uses']);
        }
        foreach (['once_per_customer', 'active'] as $k) {
            if (array_key_exists($k, $d)) {
                $cp->{$k} = (bool) $d[$k];
            }
        }
        if (array_key_exists('description', $d)) {
            $cp->description = Str::limit(trim((string) $d['description']), 200, '') ?: null;
        }
        $cp->save();

        return $cp->fresh();
    }

    /** @return array{0: \App\Modules\Sites\Models\SiteCoupon, 1: float, 2: bool} code, remise TTC sur les articles, livraison offerte */
    public function checkCoupon(Site $s, string $code, float $subtotal, string $phone = '', ?string $email = null): array
    {
        $code = strtoupper(trim($code));
        $cp = \App\Modules\Sites\Models\SiteCoupon::withoutGlobalScopes()->where('site_id', $s->id)->where('code', $code)->first();
        $today = now()->toDateString();
        if (! $cp || ! $cp->active || ($cp->starts_on && $cp->starts_on->toDateString() > $today) || ($cp->ends_on && $cp->ends_on->toDateString() < $today)) {
            throw new \DomainException('Code promo « '.$code.' » invalide ou expiré.');
        }
        if ($cp->max_uses !== null && $cp->uses >= $cp->max_uses) {
            throw new \DomainException('Code promo « '.$code.' » épuisé.');
        }
        if ($cp->min_order > 0 && $subtotal < $cp->min_order) {
            throw new \DomainException('Code « '.$code.' » valable dès '.number_format($cp->min_order, 0, ',', ' ').' F d\'achat.');
        }
        if ($cp->once_per_customer && ($phone !== '' || $email)) {
            $digits = substr(preg_replace('/\D/', '', $phone), -8);
            $used = SiteOrder::withoutGlobalScopes()->where('site_id', $s->id)->where('coupon_code', $cp->code)->where('status', '!=', 'cancelled')->get(['phone', 'email'])
                ->contains(fn ($o) => ($digits !== '' && substr(preg_replace('/\D/', '', (string) $o->phone), -8) === $digits) || ($email && $o->email && mb_strtolower($o->email) === mb_strtolower($email)));
            if ($used) {
                throw new \DomainException('Vous avez déjà utilisé le code « '.$code.' ».');
            }
        }
        $discount = match ($cp->type) {
            'percent' => round($subtotal * $cp->value / 100),
            'amount' => min($subtotal, $cp->value),
            default => 0.0,
        };

        return [$cp, (float) $discount, $cp->type === 'shipping'];
    }

    // ------------------------------------------------------------------ paniers abandonnés

    /** Panier enregistré dès que le client saisit son téléphone ou son e-mail (relance si la commande n'aboutit pas). */
    public function saveCart(Site $s, array $d): \App\Modules\Sites\Models\SiteCart
    {
        $C = \App\Modules\Sites\Models\SiteCart::class;
        $items = [];
        $ids = [];
        foreach (array_slice((array) ($d['items'] ?? []), 0, 60) as $it) {
            if (Str::isUuid((string) ($it['id'] ?? '')) && (int) ($it['quantity'] ?? 0) > 0) {
                $ids[$it['id']] = min(999, (int) $it['quantity']);
            }
        }
        $total = 0.0;
        foreach (Product::withoutGlobalScopes()->where('company_id', $s->company_id)->whereIn('id', array_keys($ids))->where('active', true)->get() as $p) {
            $price = self::ttc((float) $p->sale_price, (float) $p->tax_rate);
            $items[] = ['id' => $p->id, 'name' => $p->name, 'quantity' => $ids[$p->id], 'price' => $price];
            $total += $price * $ids[$p->id];
        }
        $phone = isset($d['phone']) ? Str::limit(trim((string) $d['phone']), 40, '') : null;
        $email = isset($d['email']) && filter_var($d['email'], FILTER_VALIDATE_EMAIL) ? mb_strtolower(trim($d['email'])) : null;
        if (! $items || (strlen(preg_replace('/\D/', '', (string) $phone)) < 8 && ! $email)) {
            throw new \DomainException('Panier incomplet.');
        }
        $cart = ! empty($d['token']) ? $C::withoutGlobalScopes()->where('site_id', $s->id)->where('token', (string) $d['token'])->whereNull('order_id')->first() : null;
        if (! $cart) {
            // Même client (téléphone ou e-mail) avec un panier ouvert récent : on le met à jour au lieu d'en créer un autre
            // (son jeton n'est pas renvoyé au navigateur : voir PublicSiteController::cart)
            $digits = substr(preg_replace('/\D/', '', (string) $phone), -8);
            $cart = $C::withoutGlobalScopes()->where('site_id', $s->id)->whereNull('order_id')->where('updated_at', '>=', now()->subDays(3))->get()
                ->first(fn ($c) => ($email && $c->email === $email) || (strlen($digits) === 8 && substr(preg_replace('/\D/', '', (string) $c->phone), -8) === $digits));
        }
        $cart ??= new $C(['site_id' => $s->id, 'company_id' => $s->company_id, 'token' => Str::random(40)]);
        $cart->fill(['name' => isset($d['name']) ? Str::limit(trim((string) $d['name']), 160, '') : $cart->name, 'phone' => $phone ?: $cart->phone, 'email' => $email ?: $cart->email, 'items' => $items, 'total' => $total]);
        $cart->save();

        return $cart;
    }

    protected function linkCarts(Site $s, SiteOrder $o, ?string $token): void
    {
        $digits = substr(preg_replace('/\D/', '', $o->phone), -8);
        $q = \App\Modules\Sites\Models\SiteCart::withoutGlobalScopes()->where('site_id', $s->id)->whereNull('order_id')->where('updated_at', '>=', now()->subDays(7));
        foreach ($q->get() as $c) {
            $same = ($token && $c->token === $token) || ($o->email && $c->email === mb_strtolower($o->email)) || ($digits && substr(preg_replace('/\D/', '', (string) $c->phone), -8) === $digits);
            if ($same) {
                $c->forceFill(['order_id' => $o->id, 'recovered_at' => $c->reminded_at ? now() : null])->save();
            }
        }
    }

    public function cartUrl(Site $s, \App\Modules\Sites\Models\SiteCart $c): string
    {
        return rtrim(app(SiteService::class)->url($s), '/').'/boutique?panier='.$c->token;
    }

    /** Relance des paniers non commandés après le délai réglé (une seule fois), par e-mail ou WhatsApp. */
    public function remindCarts(Company $c): int
    {
        $n = 0;
        $m = app(MessagingService::class);
        $avail = $m->available($c);
        foreach (Site::withoutGlobalScopes()->where('company_id', $c->id)->where('status', 'published')->get() as $s) {
            $set = $this->settings($s);
            if (! $set['enabled'] || ! $set['cart_reminders']) {
                continue;
            }
            $carts = \App\Modules\Sites\Models\SiteCart::withoutGlobalScopes()->where('site_id', $s->id)->whereNull('order_id')->whereNull('reminded_at')
                ->where('updated_at', '<=', now()->subHours($set['cart_delay_hours']))->where('updated_at', '>=', now()->subDays(3))->get();
            foreach ($carts as $cart) {
                $url = $this->cartUrl($s, $cart);
                $list = collect($cart->items)->map(fn ($i) => $i['quantity'].' × '.$i['name'])->implode(', ');
                $hello = 'Bonjour'.($cart->name ? ' '.$cart->name : '');
                $text = $hello.",\n\nVotre panier vous attend chez ".$s->name.' : '.$list.' ('.number_format($cart->total, 0, ',', ' ')." F).\nFinalisez votre commande en un clic : ".$url."\n\n".$c->name;
                $channel = null;
                try {
                    if ($cart->email && ($avail['email'] ?? null)) {
                        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;line-height:1.5"><p>'.e($hello).',</p><p>Vous avez laissé des articles dans votre panier chez <b>'.e($s->name).'</b> :</p><ul>'
                            .collect($cart->items)->map(fn ($i) => '<li>'.e($i['quantity'].' × '.$i['name']).'</li>')->implode('').'</ul><p><b>Total : '.number_format($cart->total, 0, ',', ' ').' F</b></p>'
                            .'<p><a href="'.e($url).'" style="display:inline-block;background:#1F6FEB;color:#fff;padding:12px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Finaliser ma commande</a></p></div>';
                        $channel = $m->send($c, 'email', new Message(to: $cart->email, subject: 'Votre panier vous attend — '.$s->name, html: $html, text: $text, toName: (string) $cart->name, replyTo: $c->email), 'panier')->ok ? 'email' : null;
                    }
                    if (! $channel && $cart->phone && ($avail['whatsapp'] ?? null)) {
                        $channel = $m->send($c, 'whatsapp', new Message(to: $cart->phone, text: $text, toName: (string) $cart->name), 'panier')->ok ? 'whatsapp' : null;
                    }
                } catch (\Throwable) {
                }
                if ($channel) {
                    $cart->forceFill(['reminded_at' => now(), 'reminder_channel' => $channel])->save();
                    $n++;
                }
            }
        }

        return $n;
    }

    public function cartToApi(Site $s, \App\Modules\Sites\Models\SiteCart $c): array
    {
        $phone = preg_replace('/\D/', '', (string) $c->phone);
        $wa = strlen($phone) === 10 ? '225'.$phone : $phone;
        $url = $this->cartUrl($s, $c);

        return $c->toArray() + ['restore_url' => $url, 'state' => $c->order_id ? ($c->recovered_at ? 'recovered' : 'ordered') : ($c->reminded_at ? 'reminded' : 'abandoned'),
            'whatsapp_url' => $wa ? 'https://wa.me/'.$wa.'?text='.rawurlencode('Bonjour'.($c->name ? ' '.$c->name : '').', votre panier vous attend chez '.$s->name.' : '.$url) : null];
    }

    // ------------------------------------------------------------------ avis vérifiés

    public function reviewUrl(Site $s, SiteOrder $o): string
    {
        return rtrim(app(SiteService::class)->url($s), '/').'/avis/'.$o->token;
    }

    /** Demande d'avis après la livraison (délai réglable), une fois par commande. */
    public function requestReviews(Company $c): int
    {
        $n = 0;
        $m = app(MessagingService::class);
        $avail = $m->available($c);
        foreach (Site::withoutGlobalScopes()->where('company_id', $c->id)->where('status', 'published')->get() as $s) {
            $set = $this->settings($s);
            if (! $set['enabled'] || ! $set['reviews_enabled']) {
                continue;
            }
            $orders = SiteOrder::withoutGlobalScopes()->where('site_id', $s->id)->where('status', 'delivered')->whereNull('review_requested_at')
                ->where('updated_at', '<=', now()->subDays($set['review_delay_days']))->where('updated_at', '>=', now()->subDays(30))->get();
            foreach ($orders as $o) {
                $o->forceFill(['review_requested_at' => now()])->save();
                if (\App\Modules\Sites\Models\SiteReview::withoutGlobalScopes()->where('order_id', $o->id)->exists()) {
                    continue;
                }
                $url = $this->reviewUrl($s, $o);
                $text = 'Bonjour '.$o->name.",\n\nMerci pour votre commande ".$o->number.' chez '.$s->name.". Votre avis nous aide beaucoup (30 secondes) :\n".$url."\n\n".$c->name;
                try {
                    if ($o->email && ($avail['email'] ?? null)) {
                        $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;line-height:1.5"><p>Bonjour '.e($o->name).',</p><p>Merci pour votre commande <b>'.e($o->number).'</b>. Comment s\'est-elle passée ?</p><p>'
                            .collect(range(1, 5))->map(fn ($k) => '<a href="'.e($url.'?note='.$k).'" style="display:inline-block;margin:2px;padding:10px 14px;border-radius:8px;background:#FFF4D6;color:#6B4A0E;text-decoration:none;font-weight:bold">'.str_repeat('★', $k).'</a>')->implode('')
                            .'</p><p style="color:#5B6B85;font-size:12px">'.e($c->name).'</p></div>';
                        $m->send($c, 'email', new Message(to: $o->email, subject: 'Votre avis sur votre commande '.$o->number, html: $html, text: $text, toName: $o->name), 'avis');
                        $n++;
                    } elseif ($avail['whatsapp'] ?? null) {
                        $m->send($c, 'whatsapp', new Message(to: $o->phone, text: $text, toName: $o->name), 'avis');
                        $n++;
                    }
                } catch (\Throwable) {
                }
            }
        }

        return $n;
    }

    public function saveReview(Site $s, SiteOrder $o, array $d): \App\Modules\Sites\Models\SiteReview
    {
        if ($o->status !== 'delivered') {
            throw new \DomainException('Vous pourrez donner votre avis une fois la commande livrée.');
        }
        $set = $this->settings($s);
        $prods = [];
        $ids = collect($o->items)->pluck('product_id')->all();
        foreach ((array) ($d['products'] ?? []) as $pid => $note) {
            if (in_array($pid, $ids, true) && (int) $note >= 1) {
                $prods[$pid] = max(1, min(5, (int) $note));
            }
        }
        $R = \App\Modules\Sites\Models\SiteReview::class;
        $r = $R::withoutGlobalScopes()->firstOrNew(['order_id' => $o->id], ['site_id' => $s->id, 'company_id' => $s->company_id]);
        $new = ! $r->exists;
        $r->fill(['name' => Str::limit(trim((string) ($d['name'] ?? '')) ?: $o->name, 160, ''), 'rating' => max(1, min(5, (int) $d['rating'])),
            'comment' => isset($d['comment']) && trim((string) $d['comment']) !== '' ? Str::limit(trim((string) $d['comment']), 2000, '') : null]);
        if (array_key_exists('products', $d) || ! $r->exists) {
            $r->products = $prods ?: null;
        }
        if ($new) {
            $r->status = $set['reviews_auto_publish'] ? 'published' : 'pending';
        }
        $r->save();
        if ($new) {
            $users = User::where('company_id', $s->company_id)->where('is_active', true)->get()->filter(fn ($u) => $u->hasPermission('sites.manage'))->pluck('id');
            AppNotification::send($s->company_id, $users, 'site_review', 'Nouvel avis '.str_repeat('★', $r->rating).' — '.$r->name, Str::limit((string) $r->comment, 120) ?: $o->number, 'sites', (string) $r->id);
        }

        return $r;
    }

    /** Notes publiées : moyenne de la boutique et de chaque produit. */
    public function ratings(Site $s): array
    {
        $rows = \App\Modules\Sites\Models\SiteReview::withoutGlobalScopes()->where('site_id', $s->id)->where('status', 'published')->get(['rating', 'products']);
        $prod = [];
        foreach ($rows as $r) {
            foreach ((array) $r->products as $pid => $note) {
                $prod[$pid][] = $note;
            }
        }
        // les notes des variantes comptent aussi pour leur modèle
        if ($prod) {
            foreach (Product::withoutGlobalScopes()->where('company_id', $s->company_id)->whereIn('id', array_keys($prod))->whereNotNull('parent_id')->get(['id', 'parent_id']) as $v) {
                $prod[$v->parent_id] ??= [];
                array_push($prod[$v->parent_id], ...$prod[$v->id]);
            }
        }

        return ['count' => $rows->count(), 'average' => $rows->count() ? round($rows->avg('rating'), 1) : null,
            'products' => array_map(fn ($n) => ['average' => round(array_sum($n) / count($n), 1), 'count' => count($n)], $prod)];
    }

    // ------------------------------------------------------------------ blog

    public function savePost(Site $s, ?SitePost $p, array $d, ?UploadedFile $cover = null): SitePost
    {
        $p ??= new SitePost(['site_id' => $s->id, 'company_id' => $s->company_id]);
        $title = Str::limit(trim((string) ($d['title'] ?? $p->title)), 200, '');
        if ($title === '') {
            throw new \DomainException('Donnez un titre à l\'article.');
        }
        $p->title = $title;
        if (! $p->slug || (isset($d['slug']) && $d['slug'])) {
            $base = Str::limit(Str::slug($d['slug'] ?? $title) ?: 'article', 100, '');
            $slug = $base;
            $i = 1;
            while (SitePost::withoutGlobalScopes()->where('site_id', $s->id)->where('slug', $slug)->where('id', '!=', $p->id ?? 0)->exists()) {
                $slug = $base.'-'.(++$i);
            }
            $p->slug = $slug;
        }
        foreach (['excerpt' => 400, 'body' => 60000] as $k => $max) {
            if (array_key_exists($k, $d)) {
                $p->{$k} = Str::limit(trim((string) $d[$k]), $max, '') ?: null;
            }
        }
        if (array_key_exists('published', $d)) {
            $p->published = (bool) $d['published'];
            if ($p->published && ! $p->published_at) {
                $p->published_at = now();
            }
        }
        if ($cover) {
            $ext = strtolower($cover->getClientOriginalExtension());
            if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp'], true) || $cover->getSize() > 4 * 1024 * 1024) {
                throw new \DomainException('Image PNG, JPG ou WEBP de 4 Mo au plus.');
            }
            if ($p->cover_path) {
                Storage::disk('public')->delete($p->cover_path);
            }
            $p->cover_path = 'sites/'.$s->company_id.'/'.$s->id.'/blog-'.Str::random(8).'.'.$ext;
            Storage::disk('public')->put($p->cover_path, (string) file_get_contents($cover->getRealPath()));
        }
        $p->save();

        return $p->fresh();
    }

    public function postToApi(SitePost $p, ?Site $s = null): array
    {
        $s ??= Site::find($p->site_id);
        $base = $s ? rtrim(app(SiteService::class)->url($s), '/') : '';

        return $p->toArray() + ['cover_url' => SiteService::img($p->cover_path), 'url' => $base.'/blog/'.$p->slug];
    }

    /** Mise en forme simple : paragraphes, ## titres, **gras**, *italique*, listes « - », liens [texte](https://…). */
    public static function render(?string $text): string
    {
        $out = [];
        $list = [];
        $flush = function () use (&$list, &$out) {
            if ($list) {
                $out[] = '<ul>'.implode('', array_map(fn ($l) => '<li>'.$l.'</li>', $list)).'</ul>';
                $list = [];
            }
        };
        $inline = fn (string $s) => preg_replace(
            ['/\*\*(.+?)\*\*/', '/(?<!\*)\*(?!\*)(.+?)\*/', '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/'],
            ['<strong>$1</strong>', '<em>$1</em>', '<a href="$2" target="_blank" rel="noopener nofollow">$1</a>'],
            e($s)
        );
        foreach (preg_split('/\n{2,}/', str_replace("\r", '', (string) $text)) as $block) {
            $lines = array_filter(explode("\n", trim($block)), fn ($l) => trim($l) !== '');
            if (! $lines) {
                continue;
            }
            foreach ($lines as $l) {
                if (preg_match('/^\s*[-•]\s+(.*)$/u', $l, $m)) {
                    $list[] = $inline($m[1]);

                    continue;
                }
                $flush();
                if (preg_match('/^(#{2,3})\s+(.*)$/', $l, $m)) {
                    $out[] = '<h'.strlen($m[1]).'>'.$inline($m[2]).'</h'.strlen($m[1]).'>';
                } else {
                    $out[] = '<p>'.$inline($l).'</p>';
                }
            }
            $flush();
        }

        return implode("\n", $out);
    }
}
