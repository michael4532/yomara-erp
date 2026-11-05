<?php

namespace App\Modules\Sites;

use App\Http\Controllers\Controller;
use App\Modules\Sites\Models\Site;
use App\Modules\Sites\Models\SiteMessage;
use App\Modules\Sites\Models\SiteRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/** Espace « Site web » de la société cliente. */
class SitesController extends Controller
{
    public function __construct(private SiteService $svc) {}

    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    protected function site(): ?Site
    {
        return Site::first();
    }

    protected function mine(): Site
    {
        return $this->site() ?? abort(response()->json(['message' => 'Aucun site pour le moment : choisissez un modèle et envoyez votre demande.'], 404));
    }

    public function overview()
    {
        $s = $this->site();

        return ['data' => [
            'site' => $s ? $this->svc->toApi($s) : null,
            'stats' => $s ? $this->svc->stats($s) : null,
            'requests' => SiteRequest::orderByDesc('id')->get()->map(fn ($r) => $this->svc->requestToApi($r))->values(),
            'platform' => array_intersect_key(SiteService::settings(), array_flip(['base_domain', 'server_ip'])),
        ]];
    }

    public function templates()
    {
        $c = app('tenant');
        $rows = array_map(fn ($t) => $t + ['preview_url' => URL::temporarySignedRoute('sites.template', now()->addHours(6), ['key' => $t['key'], 'c' => $c->id])], Templates::gallery());
        $sectors = collect(Templates::sectors())->map(fn ($s, $k) => ['key' => $k, 'name' => $s[0], 'icon' => $s[1]])->values();

        return ['data' => $rows, 'sectors' => $sectors, 'styles' => collect(Templates::STYLES)->map(fn ($s, $k) => ['key' => $k, 'name' => $s['name'], 'description' => $s['description']])->values(),
            'sections' => Templates::SECTIONS, 'icons' => Templates::ICONS];
    }

    public function pricing()
    {
        return ['data' => SiteService::pricing() + ['tax_rate' => SiteService::settings()['tax_rate']]];
    }

    public function estimate(Request $r)
    {
        return ['data' => SiteService::estimate(SiteService::cleanNeeds((array) $r->input('needs', [])))];
    }

    public function submit(Request $r)
    {
        $r->validate(['type' => 'required|in:template,custom', 'template' => 'nullable|string|max:60', 'site_name' => 'nullable|string|max:160', 'sector' => 'nullable|string|max:40', 'needs' => 'nullable|array']);

        return $this->guard(fn () => response()->json(['data' => $this->svc->requestToApi($this->svc->submit(app('tenant'), $r->user(), $r->all()))], 201));
    }

    public function cancel(int $id)
    {
        $req = SiteRequest::findOrFail($id);
        if (! in_array($req->status, ['submitted', 'quoted'], true)) {
            return response()->json(['message' => 'Cette demande ne peut plus être annulée.'], 422);
        }
        $req->forceFill(['status' => 'cancelled', 'decided_at' => now()])->save();

        return ['data' => $this->svc->requestToApi($req)];
    }

    public function decide(Request $r, int $id)
    {
        $v = $r->validate(['accept' => 'required|boolean', 'reason' => 'nullable|string|max:500']);

        return $this->guard(fn () => ['data' => $this->svc->requestToApi($this->svc->decide(SiteRequest::findOrFail($id), (bool) $v['accept'], $v['reason'] ?? null))]);
    }

    public function update(Request $r)
    {
        $r->validate(['name' => 'nullable|string|max:160', 'template' => 'nullable|string|max:60', 'theme' => 'nullable|array', 'content' => 'nullable|array', 'sections' => 'nullable|array', 'seo' => 'nullable|array']);

        return $this->guard(fn () => ['data' => $this->svc->toApi($this->svc->update($this->mine(), $r->only(['name', 'template', 'theme', 'content', 'sections', 'seo'])))]);
    }

    public function upload(Request $r)
    {
        $r->validate(['file' => 'required|file|max:4096', 'slot' => 'required|in:'.implode(',', SiteService::IMAGE_SLOTS)]);

        return $this->guard(fn () => ['data' => $this->svc->toApi($this->svc->uploadImage($this->mine(), $r->file('file'), $r->input('slot')))]);
    }

    public function removeImage(Request $r)
    {
        $r->validate(['path' => 'required|string|max:300']);

        return ['data' => $this->svc->toApi($this->svc->removeImage($this->mine(), $r->input('path')))];
    }

    public function publish(Request $r)
    {
        return $this->guard(fn () => ['data' => $this->svc->toApi($this->svc->publish($this->mine(), $r->boolean('published', true)))]);
    }

    public function domain(Request $r)
    {
        $r->validate(['domain' => 'nullable|string|max:200']);

        return $this->guard(function () use ($r) {
            $s = $this->svc->setDomain($this->mine(), $r->input('domain'));

            return ['data' => $this->svc->toApi($s->custom_domain ? $this->svc->checkDomain($s) : $s)];
        });
    }

    public function checkDomain()
    {
        return ['data' => $this->svc->toApi($this->svc->checkDomain($this->mine()))];
    }

    public function messages(Request $r)
    {
        $s = $this->site();
        if (! $s) {
            return ['data' => []];
        }

        return ['data' => SiteMessage::where('site_id', $s->id)->orderByDesc('id')->limit(300)->get()];
    }

    public function readMessage(Request $r, int $id)
    {
        $m = SiteMessage::findOrFail($id);
        $m->forceFill(['read_at' => $r->boolean('unread') ? null : now()])->save();

        return ['data' => $m];
    }

    public function stats(Request $r)
    {
        return ['data' => $this->svc->stats($this->mine(), max(7, min(90, (int) $r->input('days', 30))))];
    }

    // ------------------------------------------------------------------ boutique

    public function shop(ShopService $shop)
    {
        $s = $this->mine();
        $orders = \App\Modules\Sites\Models\SiteOrder::where('site_id', $s->id)->orderByDesc('id')->limit(300)->get();

        return ['data' => [
            'settings' => $shop->settings($s), 'stats' => $shop->stats($s), 'statuses' => ShopService::STATUSES, 'payment_modes' => ShopService::PAYMENT_MODES,
            'online_available' => \App\Payments\InvoicePayments::gateways(app('tenant'))->count() > 0,
            'categories' => \App\Modules\Core\Models\ProductCategory::orderBy('name')->get(['id', 'name']),
            'products' => count($shop->catalog($s)), 'shop_url' => rtrim($this->svc->url($s), '/').'/boutique',
            'orders' => $orders->map(fn ($o) => $shop->orderToApi($o, $s))->values(),
            'coupon_types' => ShopService::COUPON_TYPES,
            'coupons' => \App\Modules\Sites\Models\SiteCoupon::where('site_id', $s->id)->orderByDesc('id')->get()->map(fn ($c) => $c->toArray() + [
                'revenue' => (float) \App\Modules\Sites\Models\SiteOrder::where('site_id', $s->id)->where('coupon_code', $c->code)->where('status', '!=', 'cancelled')->sum('total'),
                'discounts' => (float) \App\Modules\Sites\Models\SiteOrder::where('site_id', $s->id)->where('coupon_code', $c->code)->where('status', '!=', 'cancelled')->sum('discount')])->values(),
            'reviews' => \App\Modules\Sites\Models\SiteReview::where('site_id', $s->id)->orderByDesc('id')->limit(300)->get()->map(function ($r) {
                $o = \App\Modules\Sites\Models\SiteOrder::find($r->order_id);

                return $r->toArray() + ['order_number' => $o?->number, 'items' => collect($o?->items ?? [])->mapWithKeys(fn ($i) => [$i['product_id'] => $i['name']])->all()];
            })->values(),
            'ratings' => $shop->ratings($s),
            'carts' => \App\Modules\Sites\Models\SiteCart::where('site_id', $s->id)->where('updated_at', '>=', now()->subDays(30))->orderByDesc('updated_at')->limit(300)->get()->map(fn ($c) => $shop->cartToApi($s, $c))->values(),
        ]];
    }

    public function saveShop(Request $r, ShopService $shop)
    {
        $r->validate(['zones' => 'nullable|array|max:20', 'zones.*.name' => 'nullable|string|max:80', 'zones.*.fee' => 'nullable|numeric|min:0', 'categories' => 'nullable|array']);

        return $this->guard(fn () => ['data' => $shop->saveSettings($this->mine(), $r->all())]);
    }

    public function orderStatus(Request $r, int $id, ShopService $shop)
    {
        $v = $r->validate(['status' => 'required|string', 'note' => 'nullable|string|max:1000', 'paid' => 'nullable|boolean']);
        $o = \App\Modules\Sites\Models\SiteOrder::findOrFail($id);
        if (app('tenant')->hasModule('ecommerce')) {
            // Boutique en ligne : chaque étape passe par le circuit complet (facture, livraison, encaissement)
            $ec = app(\App\Modules\Ecommerce\EcommerceService::class);

            return $this->guard(function () use ($ec, $o, $v, $shop) {
                $paid = ! empty($v['paid']) && $o->payment_status !== 'paid';
                $o = match ($v['status']) {
                    'confirmed' => $ec->confirm($o),
                    'preparing' => $ec->prepare($o),
                    'shipped' => $o->status === 'shipped' ? $o : $ec->ship($o),
                    'delivered' => $ec->deliver($o, ['collected' => $paid]),
                    'cancelled' => $ec->cancel($o, $v['note'] ?? null),
                    default => $o->status === $v['status'] ? $o : throw new \DomainException('État inconnu.'),
                };
                if ($paid && $o->payment_status !== 'paid' && $o->status !== 'cancelled') {
                    $o = $ec->pay($o, []);
                }

                return ['data' => $shop->orderToApi($o->fresh())];
            });
        }

        return $this->guard(fn () => ['data' => $shop->orderToApi($shop->setStatus($o, $v['status'], $v['note'] ?? null, (bool) ($v['paid'] ?? false)))]);
    }

    // ------------------------------------------------------------------ fiches produits

    public function productPages(ShopService $shop)
    {
        return ['data' => $shop->pagesToApi($this->mine())];
    }

    protected function pageResult(ShopService $shop, \App\Modules\Sites\Models\Site $s, string $id): array
    {
        return ['data' => collect($shop->pagesToApi($s))->firstWhere('id', $id)];
    }

    public function saveProductPage(Request $r, string $id, ShopService $shop)
    {
        $r->validate(['description' => 'nullable|string|max:30000', 'badge' => 'nullable|string|max:40', 'compare_at' => 'nullable|numeric|min:0', 'related' => 'nullable|array|max:12',
            'photo_tags' => 'nullable|array', 'photo_order' => 'nullable|array|max:10']);
        $s = $this->mine();
        $p = \App\Modules\Core\Models\Product::findOrFail($id);

        return $this->guard(function () use ($r, $s, $p, $shop) {
            $shop->savePage($s, $p, $r->only(['description', 'badge', 'compare_at', 'related', 'photo_tags', 'photo_order']));

            return $this->pageResult($shop, $s, $p->id);
        });
    }

    public function productPhoto(Request $r, string $id, ShopService $shop)
    {
        $r->validate(['file' => 'required|file|max:4096', 'tag' => 'nullable|string|max:60']);
        $s = $this->mine();
        $p = \App\Modules\Core\Models\Product::findOrFail($id);

        return $this->guard(function () use ($r, $s, $p, $shop) {
            $shop->addPhoto($s, $p, $r->file('file'), $r->input('tag'));

            return $this->pageResult($shop, $s, $p->id);
        });
    }

    public function removeProductPhoto(Request $r, string $id, ShopService $shop)
    {
        $r->validate(['path' => 'required|string|max:300']);
        $s = $this->mine();
        $p = \App\Modules\Core\Models\Product::findOrFail($id);
        $shop->removePhoto($s, $p, $r->input('path'));

        return $this->pageResult($shop, $s, $p->id);
    }

    // ------------------------------------------------------------------ blog

    public function posts(ShopService $shop)
    {
        $s = $this->mine();

        return ['data' => \App\Modules\Sites\Models\SitePost::where('site_id', $s->id)->orderByDesc('id')->get()->map(fn ($p) => $shop->postToApi($p, $s))->values()];
    }

    public function savePost(Request $r, ShopService $shop, ?int $id = null)
    {
        $r->validate(['title' => ($id ? 'nullable' : 'required').'|string|max:200', 'slug' => 'nullable|string|max:120', 'excerpt' => 'nullable|string|max:400', 'body' => 'nullable|string|max:60000',
            'published' => 'nullable|boolean', 'cover' => 'nullable|file|max:4096']);
        $s = $this->mine();
        $p = $id ? \App\Modules\Sites\Models\SitePost::where('site_id', $s->id)->findOrFail($id) : null;

        return $this->guard(fn () => response()->json(['data' => $shop->postToApi($shop->savePost($s, $p, $r->only(['title', 'slug', 'excerpt', 'body']) + ($r->has('published') ? ['published' => $r->boolean('published')] : []), $r->file('cover')), $s)], $id ? 200 : 201));
    }

    public function deletePost(int $id)
    {
        $p = \App\Modules\Sites\Models\SitePost::findOrFail($id);
        if ($p->cover_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($p->cover_path);
        }
        $p->delete();

        return ['data' => true];
    }

    // ------------------------------------------------------------------ codes promo, avis

    public function saveCoupon(Request $r, ShopService $shop, ?int $id = null)
    {
        $s = $this->mine();
        $cp = $id ? \App\Modules\Sites\Models\SiteCoupon::where('site_id', $s->id)->findOrFail($id) : null;

        return $this->guard(fn () => response()->json(['data' => $shop->saveCoupon($s, $cp, $r->only(['code', 'type', 'value', 'min_order', 'starts_on', 'ends_on', 'max_uses', 'once_per_customer', 'active', 'description']))], $id ? 200 : 201));
    }

    public function deleteCoupon(int $id)
    {
        \App\Modules\Sites\Models\SiteCoupon::findOrFail($id)->delete();

        return ['data' => true];
    }

    public function updateReview(Request $r, int $id)
    {
        $v = $r->validate(['status' => 'nullable|in:published,hidden,pending', 'reply' => 'nullable|string|max:2000']);
        $rv = \App\Modules\Sites\Models\SiteReview::findOrFail($id);
        if (array_key_exists('status', $v) && $v['status']) {
            $rv->status = $v['status'];
        }
        if ($r->has('reply')) {
            $rv->reply = trim((string) $v['reply']) ?: null;
            $rv->replied_at = $rv->reply ? now() : null;
        }
        $rv->save();

        return ['data' => $rv];
    }
}
