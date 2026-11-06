<?php

namespace App\Modules\Woocommerce;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WooController extends Controller
{
    protected string $platform = 'woocommerce';

    public function __construct(protected WooSync $sync) {}

    protected function store(int $id): object
    {
        $s = DB::table('woo_stores')->where('company_id', app('tenant')->id)->where('platform', $this->platform)->where('id', $id)->first();
        abort_unless($s, 404);

        return $s;
    }

    protected function fail(\Throwable $e)
    {
        return response()->json(['message' => $e->getMessage()], 422);
    }

    protected function row(object $s): array
    {
        $links = DB::table('woo_links')->where('store_id', $s->id);
        $info = $this->sync->info($s);

        return ['id' => $s->id, 'name' => $s->name, 'url' => $s->url, 'status' => $s->status, 'error' => $s->error, 'settings' => $this->sync->settings($s),
            'currency' => $info['currency'] ?? null, 'prices_include_tax' => $info['prices_include_tax'] ?? null, 'version' => $info['version'] ?? null,
            'webhooks' => ! empty($info['webhooks']), 'webhook_error' => $info['webhook_error'] ?? null, 'webhook_url' => url('/api/'.$this->platform.'-webhook/'.$s->token), 'platform' => $this->platform,
            'products' => (clone $links)->whereIn('entity', ['product', 'variation'])->count(), 'products_online' => (clone $links)->whereIn('entity', ['product', 'variation'])->whereNotNull('remote_id')->count(),
            'products_errors' => (clone $links)->whereIn('entity', ['product', 'variation'])->whereNotNull('error')->count(),
            'orders' => (clone $links)->where('entity', 'order')->count(), 'orders_30' => (clone $links)->where('entity', 'order')->where('created_at', '>=', now()->subDays(30))->count(),
            'last_products_sync_at' => $s->last_products_sync_at, 'last_orders_sync_at' => $s->last_orders_sync_at, 'last_webhook_at' => $s->last_webhook_at];
    }

    public function index()
    {
        $c = app('tenant');

        return ['data' => DB::table('woo_stores')->where('company_id', $c->id)->where('platform', $this->platform)->orderBy('id')->get()->map(fn ($s) => $this->row($s))->values(),
            'statuses' => WooSync::STATUSES,
            'warehouses' => \Illuminate\Support\Facades\Schema::hasTable('warehouses') ? DB::table('warehouses')->where('company_id', $c->id)->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) : []];
    }

    public function connect(Request $r)
    {
        $d = $r->validate(['url' => 'required|string|max:255', 'consumer_key' => 'required|string|max:120', 'consumer_secret' => 'required|string|max:120', 'name' => 'nullable|string|max:120',
            'import_days' => 'nullable|integer|min:0|max:60']);
        try {
            $s = $this->sync->connect(app('tenant'), $d);
        } catch (WooException $e) {
            return $this->fail($e);
        }

        return ['data' => $this->row($s)];
    }

    /**
     * Connexion en un clic (WooCommerce /wc-auth/v1/authorize) : renvoie l'adresse d'approbation à ouvrir.
     * WordPress envoie ensuite les clés à authorizeCallback ; l'application suit l'état avec authorizeStatus.
     */
    public function authorizeStart(Request $r)
    {
        abort_unless($this->platform === 'woocommerce', 404);
        $d = $r->validate(['url' => 'required|string|max:255', 'name' => 'nullable|string|max:120', 'import_days' => 'nullable|integer|min:0|max:60']);
        $url = WooClient::normalizeUrl($d['url']);
        try {
            \App\Support\UrlGuard::assertPublicHost((string) parse_url($url, PHP_URL_HOST));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Adresse du site invalide.'], 422);
        }
        $c = app('tenant');
        if (DB::table('woo_stores')->where('company_id', $c->id)->where('url', $url)->exists()) {
            return response()->json(['message' => 'Cette boutique est déjà connectée.'], 422);
        }
        $state = \Illuminate\Support\Str::random(40);
        \Illuminate\Support\Facades\Cache::put('wooauth:'.$state, ['company_id' => $c->id, 'user_id' => auth()->id(), 'url' => $url, 'name' => $d['name'] ?? null,
            'import_days' => $d['import_days'] ?? 7, 'status' => 'pending'], now()->addMinutes(30));

        return ['state' => $state, 'url' => $url.'/wc-auth/v1/authorize?'.http_build_query([
            'app_name' => config('erp.name', 'Yomara ERP').' — '.$c->name, 'scope' => 'read_write', 'user_id' => $state,
            'return_url' => url('/woocommerce/connecte'), 'callback_url' => url('/api/woocommerce-auth/'.$state),
        ])];
    }

    public function authorizeStatus(string $state)
    {
        $p = \Illuminate\Support\Facades\Cache::get('wooauth:'.$state);
        abort_unless($p && (int) $p['company_id'] === (int) app('tenant')->id, 404);

        return ['status' => $p['status'], 'message' => $p['message'] ?? null, 'store' => isset($p['store_id']) ? $this->row(DB::table('woo_stores')->find($p['store_id'])) : null];
    }

    /** Appelé par WordPress après approbation : { key_id, user_id, consumer_key, consumer_secret, key_permissions }. */
    public function authorizeCallback(Request $r, string $state)
    {
        $key = 'wooauth:'.$state;
        $p = \Illuminate\Support\Facades\Cache::get($key);
        if (! $p || $p['status'] !== 'pending' || ! hash_equals($state, (string) $r->input('user_id'))) {
            return response()->json(['message' => 'Demande inconnue ou expirée.'], 400);
        }
        $ck = (string) $r->input('consumer_key');
        $cs = (string) $r->input('consumer_secret');
        if (! preg_match('/^ck_[a-f0-9]{20,64}$/', $ck) || ! preg_match('/^cs_[a-f0-9]{20,64}$/', $cs) || $r->input('key_permissions', 'read_write') !== 'read_write') {
            return response()->json(['message' => 'Clés invalides.'], 400);
        }
        $c = \App\Models\Company::find($p['company_id']);
        if (! $c || ! $c->hasModule('woocommerce')) {
            return response()->json(['message' => 'Module indisponible.'], 400);
        }
        app()->instance('tenant', $c);
        try {
            $s = $this->sync->connect($c, ['url' => $p['url'], 'consumer_key' => $ck, 'consumer_secret' => $cs, 'name' => $p['name'], 'import_days' => $p['import_days']]);
            \Illuminate\Support\Facades\Cache::put($key, ['status' => 'connected', 'store_id' => $s->id] + $p, now()->addMinutes(30));
            \App\Models\AppNotification::send($c->id, array_filter([$p['user_id']]), 'woocommerce', 'Boutique WooCommerce connectée', $p['url'], 'woocommerce', null);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Cache::put($key, ['status' => 'error', 'message' => $e instanceof WooException ? $e->getMessage() : 'Connexion impossible.'] + $p, now()->addMinutes(30));
        }

        return response()->json(['ok' => true]);
    }

    public function update(Request $r, int $id)
    {
        $s = $this->store($id);
        $d = $r->validate(['name' => 'sometimes|string|max:120', 'status' => 'sometimes|in:active,paused', 'settings' => 'sometimes|array',
            'settings.import_statuses' => 'sometimes|array', 'settings.import_statuses.*' => 'in:'.implode(',', array_keys(WooSync::STATUSES)),
            'settings.publish_new_as' => 'sometimes|in:publish,draft', 'settings.stock_buffer' => 'sometimes|integer|min:0|max:1000', 'settings.order_prefix' => 'sometimes|string|max:10|alpha_dash',
            'settings.warehouse_id' => 'sometimes|nullable|string|max:36']);
        $row = ['updated_at' => now()];
        foreach (['name', 'status'] as $k) {
            if (isset($d[$k])) {
                $row[$k] = $d[$k];
            }
        }
        if (isset($d['settings'])) {
            $set = $this->sync->settings($s);
            foreach (WooSync::DEFAULTS as $k => $def) {
                if (array_key_exists($k, $d['settings'])) {
                    $v = $d['settings'][$k];
                    $set[$k] = is_bool($def) ? (bool) $v : (is_int($def) ? (int) $v : $v);
                }
            }
            $row['settings'] = json_encode($set);
            DB::table('woo_links')->where('store_id', $s->id)->whereIn('entity', ['product', 'variation'])->update(['hash' => null]); // tout renvoyer
        }
        DB::table('woo_stores')->where('id', $s->id)->update($row);

        return ['data' => $this->row(DB::table('woo_stores')->find($s->id))];
    }

    public function destroy(int $id)
    {
        $this->sync->disconnect($this->store($id));

        return response()->noContent();
    }

    public function sync(Request $r, int $id)
    {
        $s = $this->store($id);
        $c = app('tenant');
        $what = (string) $r->input('what', 'all');
        try {
            $out = [];
            if (in_array($what, ['all', 'orders'], true)) {
                $out['orders'] = $this->sync->pullOrders($c, $s);
            }
            if (in_array($what, ['all', 'products'], true)) {
                $out['products'] = $this->sync->pushProducts($c, DB::table('woo_stores')->find($s->id), $r->boolean('force'));
            }
            if ($what === 'webhooks') {
                $this->sync->refreshInfo($s);
                $this->sync->ensureWebhooks($s);
            }
            DB::table('woo_stores')->where('id', $s->id)->update(['status' => 'active', 'error' => null]);
        } catch (WooException $e) {
            DB::table('woo_stores')->where('id', $s->id)->update(['status' => 'error', 'error' => $e->getMessage()]);

            return $this->fail($e);
        }

        return ['data' => $out, 'store' => $this->row(DB::table('woo_stores')->find($s->id))];
    }

    public function link(Request $r, int $id)
    {
        try {
            return ['data' => $this->sync->linkBySku(app('tenant'), $this->store($id), $r->boolean('create_missing'))];
        } catch (WooException $e) {
            return $this->fail($e);
        }
    }

    /** Produits de l'ERP avec leur état sur le site. */
    public function products(Request $r, int $id)
    {
        $s = $this->store($id);
        $c = app('tenant');
        $links = DB::table('woo_links')->where('store_id', $s->id)->whereIn('entity', ['product', 'variation'])->get()->keyBy('local_id');
        $q = Product::query()->where('is_template', false)->with('category')->orderBy('name');
        if ($term = trim((string) $r->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', '%'.$term.'%')->orWhere('sku', 'like', '%'.$term.'%'));
        }
        $filter = (string) $r->query('filter', 'all');
        if ($filter === 'published') {
            $q->whereIn('id', $links->keys());
        } elseif ($filter === 'unpublished') {
            $q->whereNotIn('id', $links->keys())->where('active', true);
        } elseif ($filter === 'errors') {
            $q->whereIn('id', $links->whereNotNull('error')->keys());
        }
        $page = $q->paginate(50);
        $ids = collect($page->items())->where('track_stock', true)->pluck('id')->all();
        $stock = $this->sync->available($c, $s, $ids);

        return ['data' => collect($page->items())->map(function ($p) use ($links, $stock, $s) {
            $l = $links[$p->id] ?? null;

            return ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'category' => $p->category?->name, 'price' => round((float) $p->sale_price * (1 + (float) $p->tax_rate / 100), 2),
                'active' => (bool) $p->active, 'image' => is_string($p->image) && str_starts_with($p->image, 'http') ? $p->image : null,
                'stock' => $p->track_stock ? ($stock[$p->id] ?? 0) : null, 'published' => (bool) $l, 'online' => (bool) $l?->remote_id, 'variation' => $l?->entity === 'variation',
                'remote_url' => $l?->remote_id ? $this->productUrl($s, $l) : null, 'error' => $l?->error, 'synced_at' => $l?->synced_at];
        })->values(), 'total' => $page->total(), 'last_page' => $page->lastPage()];
    }

    public function choose(Request $r, int $id)
    {
        $s = $this->store($id);
        $d = $r->validate(['product_ids' => 'array|max:2000', 'product_ids.*' => 'uuid', 'publish' => 'required|boolean', 'all_active' => 'sometimes|boolean', 'category_id' => 'sometimes|nullable|uuid']);
        $ids = $d['product_ids'] ?? [];
        if (! empty($d['all_active']) || ! empty($d['category_id'])) {
            $ids = Product::where('active', true)->where('is_template', false)->where('sale_price', '>', 0)->when($d['category_id'] ?? null, fn ($q, $cat) => $q->where('category_id', $cat))->pluck('id')->all();
        }
        $n = $this->sync->choose($s, $ids, $d['publish']);
        $push = null;
        if ($d['publish'] && $n) {
            try {
                $push = $this->sync->pushProducts(app('tenant'), $s);
            } catch (WooException $e) {
                return response()->json(['changed' => $n, 'message' => 'Produits choisis, mais l\'envoi au site a échoué : '.$e->getMessage()], 422);
            }
        }

        return ['changed' => $n, 'push' => $push, 'store' => $this->row(DB::table('woo_stores')->find($s->id))];
    }

    public function orders(Request $r, int $id)
    {
        $s = $this->store($id);
        $links = DB::table('woo_links')->where('store_id', $s->id)->where('entity', 'order')->orderByDesc('remote_id')->limit(300)->get();
        $docs = DB::table('trade_documents')->whereIn('id', $links->pluck('local_id'))->get(['id', 'number', 'status', 'total', 'partner_id'])->keyBy('id');
        $inv = DB::table('trade_documents')->whereIn('source_id', $links->pluck('local_id'))->where('type', 'invoice')->where('status', '!=', 'cancelled')->get(['id', 'number', 'source_id', 'payment_status', 'total'])->keyBy('source_id');
        $partners = DB::table('partners')->whereIn('id', $docs->pluck('partner_id')->filter())->pluck('name', 'id');

        return ['data' => $links->map(function ($l) use ($docs, $inv, $partners, $s) {
            $m = (array) json_decode((string) $l->meta, true);
            $d = $docs[$l->local_id] ?? null;
            $i = $inv[$l->local_id] ?? null;

            return ['id' => $l->id, 'remote_id' => $l->remote_id, 'number' => $m['number'] ?? (string) $l->remote_id, 'status' => $m['status'] ?? null, 'status_label' => WooSync::STATUSES[$m['status'] ?? ''] ?? ($m['status'] ?? ''),
                'payment' => $m['payment_title'] ?? null, 'payment_method' => $m['payment_method'] ?? null, 'total' => $m['total'] ?? null, 'currency' => $m['currency'] ?? null, 'date' => $m['date'] ?? null,
                'customer' => $d && $d->partner_id ? ($partners[$d->partner_id] ?? null) : ($m['customer'] ?? null),
                'order' => $d ? ['id' => $d->id, 'number' => $d->number, 'status' => $d->status] : null,
                'invoice' => $i ? ['id' => $i->id, 'number' => $i->number, 'payment_status' => $i->payment_status] : null,
                'admin_url' => $this->orderUrl($s, $l)];
        })->values()];
    }

    protected function productUrl(object $s, object $l): string
    {
        return $s->url.'/?p='.($l->remote_parent_id ?: $l->remote_id);
    }

    protected function orderUrl(object $s, object $l): string
    {
        return $s->url.'/wp-admin/post.php?post='.$l->remote_id.'&action=edit';
    }

    public function setStatus(Request $r, int $id, int $linkId)
    {
        $s = $this->store($id);
        $d = $r->validate(['status' => 'required|in:processing,on-hold,completed,cancelled']);
        $l = DB::table('woo_links')->where('store_id', $s->id)->where('entity', 'order')->where('id', $linkId)->first();
        abort_unless($l, 404);
        try {
            $this->sync->setRemoteStatus($s, $l, $d['status']);
        } catch (WooException $e) {
            return $this->fail($e);
        }

        return ['ok' => true];
    }

    public function logs(int $id)
    {
        $s = $this->store($id);

        return ['data' => DB::table('woo_logs')->where('store_id', $s->id)->orderByDesc('id')->limit(150)->get(['level', 'message', 'created_at'])];
    }

    /** Webhook public WooCommerce. */
    public function webhook(Request $r, string $token)
    {
        $s = DB::table('woo_stores')->where('token', $token)->where('platform', $this->platform)->first();
        if (! $s) {
            return response()->json(['ok' => false], 404);
        }
        // Ping de création du webhook (formulaire « webhook_id=… », sans signature)
        if (! $r->header('X-WC-Webhook-Signature') && $r->has('webhook_id')) {
            return response()->json(['ok' => true]);
        }
        try {
            $ok = $this->sync->webhook($s, $r->getContent(), $r->header('X-WC-Webhook-Signature'), $r->header('X-WC-Webhook-Topic'));
        } catch (\Throwable $e) {
            $this->sync->log($s, 'Webhook : '.$e->getMessage(), 'error');

            return response()->json(['ok' => false], 200); // pas de nouvelle tentative en boucle : le relevé rattrapera
        }

        return response()->json(['ok' => $ok], $ok ? 200 : 401);
    }
}
