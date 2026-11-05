<?php

namespace App\Modules\Woocommerce;

use App\Models\Company;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;
use App\Modules\Ventes\Models\SalesDocument;
use App\Modules\Ventes\Models\SalesPayment;
use App\Services\EntityWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connecteur WooCommerce. L'ERP est la référence des produits, des prix et du stock : il les publie sur le site
 * (envoi seulement de ce qui a changé). Les commandes du site arrivent dans l'ERP (webhook en direct + relevé de
 * secours toutes les 5 minutes) : client reconnu ou créé, commande client, facture et encaissement quand la commande
 * est payée ; le statut revient sur le site quand l'ERP enregistre le paiement d'une commande à la livraison ou par virement.
 */
class WooSync
{
    public const DEFAULTS = [
        'publish_stock' => true, 'publish_prices' => true, 'publish_new_as' => 'publish', // publish, draft
        'import_orders' => true, 'import_statuses' => ['processing', 'on-hold', 'completed'],
        'invoice_paid' => true, // facture + encaissement quand la commande est payée (paiement en ligne ou terminée)
        'push_status' => true, // statut renvoyé au site quand l'ERP enregistre le paiement
        'warehouse_id' => null, 'stock_buffer' => 0, 'order_prefix' => 'WOO',
    ];

    public const STATUSES = ['pending' => 'En attente de paiement', 'processing' => 'En cours', 'on-hold' => 'En attente', 'completed' => 'Terminée', 'cancelled' => 'Annulée',
        'refunded' => 'Remboursée', 'failed' => 'Échouée', 'checkout-draft' => 'Brouillon'];

    public const METHODS = ['cod' => 'cash', 'bacs' => 'bank_transfer', 'cheque' => 'cheque', 'stripe' => 'card', 'paypal' => 'card', 'ppcp-gateway' => 'card'];

    /** Encaissement enregistré par le connecteur lui-même : ne pas renvoyer de statut au site. */
    public static bool $importing = false;

    public const PLATFORM = 'woocommerce';

    public const MODULE = 'woocommerce';

    public const LABEL = 'WooCommerce';

    public function __construct(protected EntityWriter $writer) {}

    /** Retire un produit du site sans le supprimer (brouillon). */
    protected function unpublishRemote(object $store, object $link): void
    {
        if ($link->remote_id && $link->entity === 'product') {
            rescue(fn () => WooClient::for($store)->put('products/'.$link->remote_id, ['status' => 'draft']), null, false);
        }
    }

    // ------------------------------------------------------------------ boutique

    public function settings(object $store): array
    {
        return array_replace(self::DEFAULTS, (array) json_decode((string) $store->settings, true));
    }

    public function info(object $store): array
    {
        return (array) json_decode((string) $store->info, true);
    }

    protected function setInfo(object $store, array $patch): void
    {
        DB::table('woo_stores')->where('id', $store->id)->update(['info' => json_encode(array_replace($this->info($store), $patch)), 'updated_at' => now()]);
        $store->info = DB::table('woo_stores')->where('id', $store->id)->value('info');
    }

    public function log(object $store, string $msg, string $level = 'info'): void
    {
        DB::table('woo_logs')->insert(['company_id' => $store->company_id, 'store_id' => $store->id, 'level' => $level, 'message' => Str::limit($msg, 495, '…'), 'created_at' => now()]);
        $old = DB::table('woo_logs')->where('store_id', $store->id)->orderByDesc('id')->skip(300)->value('id');
        if ($old) {
            DB::table('woo_logs')->where('store_id', $store->id)->where('id', '<=', $old)->delete();
        }
    }

    /** Vérifie la clé, lit les réglages du site (devise, prix TTC), crée les webhooks de commandes. */
    public function connect(Company $c, array $d): object
    {
        $url = WooClient::normalizeUrl($d['url']);
        $client = new WooClient($url, trim($d['consumer_key']), trim($d['consumer_secret']));
        $r = $client->call('GET', 'products', ['per_page' => 1]);
        if ($r->status() === 401 || ($r->status() === 400 && str_contains((string) $r->json('code'), 'authentication'))) {
            $client->queryAuth = true; // en-tête Authorization supprimé par l'hébergeur (Apache/CGI)
            $r = $client->call('GET', 'products', ['per_page' => 1]);
        }
        if (! $r->successful()) {
            throw new WooException(WooClient::errorOf($r));
        }
        if (DB::table('woo_stores')->where('company_id', $c->id)->where('url', $url)->exists()) {
            throw new WooException('Cette boutique est déjà connectée.');
        }
        $id = DB::table('woo_stores')->insertGetId(['company_id' => $c->id, 'name' => Str::limit(trim($d['name'] ?? '') ?: (string) parse_url($url, PHP_URL_HOST), 120, ''), 'url' => $url,
            'credentials' => Crypt::encryptString(json_encode(['key' => trim($d['consumer_key']), 'secret' => trim($d['consumer_secret'])])), 'query_auth' => $client->queryAuth,
            'token' => Str::lower(Str::random(40)), 'webhook_secret' => Str::random(40), 'status' => 'active', 'settings' => json_encode(self::DEFAULTS), 'platform' => 'woocommerce',
            'last_orders_sync_at' => now()->subDays(max(0, min(60, (int) ($d['import_days'] ?? 7)))), 'created_at' => now(), 'updated_at' => now()]);
        $store = DB::table('woo_stores')->find($id);
        $this->refreshInfo($store);
        $this->ensureWebhooks($store);
        $this->log($store, 'Boutique connectée ('.$url.').');

        return DB::table('woo_stores')->find($id);
    }

    public function refreshInfo(object $store): void
    {
        $client = WooClient::for($store);
        $get = function (string $group, string $id) use ($client) {
            try {
                return $client->get('settings/'.$group.'/'.$id)['value'] ?? null;
            } catch (\Throwable) {
                return null;
            }
        };
        $sys = rescue(fn () => $client->get('system_status')['environment'] ?? [], [], false);
        $this->setInfo($store, ['currency' => $get('general', 'woocommerce_currency'), 'prices_include_tax' => $get('tax', 'woocommerce_prices_include_tax') === 'yes',
            'calc_taxes' => $get('general', 'woocommerce_calc_taxes') === 'yes', 'version' => $sys['version'] ?? null, 'site_title' => $sys['site_title'] ?? null]);
    }

    public function ensureWebhooks(object $store): void
    {
        $client = WooClient::for($store);
        $url = url('/api/woocommerce-webhook/'.$store->token);
        $hooks = [];
        try {
            $existing = collect($client->get('webhooks', ['per_page' => 100]))->where('delivery_url', $url)->keyBy('topic');
            foreach (['order.created', 'order.updated'] as $topic) {
                $h = $existing[$topic] ?? $client->post('webhooks', ['name' => 'Yomara ERP — '.$topic, 'topic' => $topic, 'delivery_url' => $url, 'secret' => $store->webhook_secret, 'status' => 'active']);
                $hooks[$topic] = $h['id'] ?? null;
            }
            $this->setInfo($store, ['webhooks' => $hooks, 'webhook_error' => null]);
        } catch (\Throwable $e) {
            // Sans webhook, le relevé toutes les 5 minutes prend le relais
            $this->setInfo($store, ['webhook_error' => $e->getMessage()]);
            $this->log($store, 'Webhooks non créés ('.$e->getMessage().') : les commandes seront relevées toutes les 5 minutes.', 'warning');
        }
    }

    public function disconnect(object $store): void
    {
        $client = WooClient::for($store);
        foreach ((array) ($this->info($store)['webhooks'] ?? []) as $hid) {
            if ($hid) {
                rescue(fn () => $client->call('DELETE', 'webhooks/'.$hid, ['force' => true]), null, false);
            }
        }
        DB::table('woo_links')->where('store_id', $store->id)->delete();
        DB::table('woo_logs')->where('store_id', $store->id)->delete();
        DB::table('woo_stores')->where('id', $store->id)->delete();
    }

    // ------------------------------------------------------------------ produits

    /** Stock vendable par produit : stock physique − commandes en cours non facturées (boutique du site et WooCommerce) − marge de sécurité. */
    public function available(Company $c, object $store, array $ids): array
    {
        if (! $ids || ! $c->hasModule('stock')) {
            return [];
        }
        $set = $this->settings($store);
        $q = DB::table('stock_moves')->where('company_id', $c->id)->whereIn('product_id', $ids)->whereNull('deleted_at');
        if ($set['warehouse_id'] && \Illuminate\Support\Facades\Schema::hasColumn('stock_moves', 'warehouse_id')) {
            $q->where('warehouse_id', $set['warehouse_id']);
        }
        $out = $q->selectRaw('product_id, sum(quantity) q')->groupBy('product_id')->pluck('q', 'product_id')->map(fn ($v) => (float) $v)->all();
        if ($c->hasModule('ecommerce')) {
            foreach (app(\App\Modules\Ecommerce\EcommerceService::class)->reserved($c, $ids) as $pid => $qty) {
                $out[$pid] = ($out[$pid] ?? 0) - $qty;
            }
        }
        foreach ($this->reservedByOrders($c, $ids) as $pid => $qty) {
            $out[$pid] = ($out[$pid] ?? 0) - $qty;
        }
        foreach ($ids as $id) {
            $out[$id] = max(0, (int) floor(($out[$id] ?? 0) - (int) $set['stock_buffer']));
        }

        return $out;
    }

    /** Quantités des commandes WooCommerce importées et pas encore facturées (le site a déjà retiré ce stock). */
    public function reservedByOrders(Company $c, array $ids): array
    {
        $docs = DB::table('woo_links')->where('company_id', $c->id)->where('entity', 'order')->whereNotNull('local_id')->pluck('local_id')->all();
        if (! $docs) {
            return [];
        }
        $open = DB::table('trade_documents')->whereIn('id', $docs)->where('type', 'order')->where('status', '!=', 'cancelled')->whereNull('deleted_at')->pluck('id')->all();
        $invoiced = DB::table('trade_documents')->whereIn('source_id', $open)->where('type', 'invoice')->where('status', '!=', 'cancelled')->whereNull('deleted_at')->pluck('source_id')->flip();
        $open = array_values(array_filter($open, fn ($d) => ! isset($invoiced[$d])));

        return $open ? DB::table('trade_document_lines')->whereIn('document_id', $open)->whereIn('product_id', $ids)->selectRaw('product_id, sum(quantity) q')->groupBy('product_id')
            ->pluck('q', 'product_id')->map(fn ($v) => (float) $v)->all() : [];
    }

    protected function price(object $store, Product $p): string
    {
        $ttc = $this->info($store)['prices_include_tax'] ?? true;
        $v = $ttc || ! ($this->info($store)['calc_taxes'] ?? false) ? (float) $p->sale_price * (1 + (float) $p->tax_rate / 100) : (float) $p->sale_price;
        $dec = in_array(strtoupper((string) ($this->info($store)['currency'] ?? 'XOF')), \App\Modules\Core\Models\TradeDocumentLine::ZERO_DECIMAL, true) ? 0 : 2;

        return number_format(round($v, $dec), $dec, '.', '');
    }

    /** Choisit (ou retire) les produits publiés sur le site. */
    public function choose(object $store, array $productIds, bool $publish): int
    {
        $n = 0;
        foreach (Product::whereIn('id', $productIds)->get() as $p) {
            $link = DB::table('woo_links')->where('store_id', $store->id)->whereIn('entity', ['product', 'variation'])->where('local_id', $p->id)->first();
            if ($publish && ! $link) {
                DB::table('woo_links')->insert(['company_id' => $store->company_id, 'store_id' => $store->id, 'entity' => 'product', 'local_id' => $p->id, 'created_at' => now(), 'updated_at' => now()]);
                $n++;
            } elseif (! $publish && $link) {
                $this->unpublishRemote($store, $link); // masqué, pas supprimé
                DB::table('woo_links')->where('id', $link->id)->delete();
                $n++;
            }
        }

        return $n;
    }

    /**
     * Publie les produits choisis : création sur le site des nouveaux, mise à jour du prix, du stock et des
     * informations de ceux qui ont changé depuis le dernier envoi (empreinte).
     */
    public function pushProducts(Company $c, object $store, bool $force = false): array
    {
        $set = $this->settings($store);
        $client = WooClient::for($store);
        $links = DB::table('woo_links')->where('store_id', $store->id)->whereIn('entity', ['product', 'variation'])->whereNotNull('local_id')->get();
        $products = Product::withoutGlobalScopes()->where('company_id', $c->id)->whereIn('id', $links->pluck('local_id'))->with('category')->get()->keyBy('id');
        $stock = $this->available($c, $store, $products->where('track_stock', true)->keys()->all());
        $create = $update = $variations = [];
        $hashes = [];
        foreach ($links as $l) {
            $p = $products[$l->local_id] ?? null;
            if (! $p) {
                continue;
            }
            $manage = $p->track_stock && $c->hasModule('stock');
            $h = null;
            if ($l->entity === 'variation') {
                $body = array_filter(['regular_price' => $set['publish_prices'] ? $this->price($store, $p) : null], fn ($v) => $v !== null)
                    + ($set['publish_stock'] && $manage ? ['manage_stock' => true, 'stock_quantity' => $stock[$p->id] ?? 0] : []);
            } else {
                $body = ['name' => $p->name, 'sku' => $p->sku ?: null]
                    + ($set['publish_prices'] ? ['regular_price' => $this->price($store, $p)] : [])
                    + ($set['publish_stock'] ? ($manage ? ['manage_stock' => true, 'stock_quantity' => $stock[$p->id] ?? 0] : ['manage_stock' => false, 'stock_status' => 'instock']) : []);
                if ($l->remote_id && ! $p->active) {
                    $body['status'] = 'draft';
                }
                $h = md5(json_encode($body));
                if (! $l->remote_id) {
                    $body += ['type' => 'simple', 'status' => $p->active ? $set['publish_new_as'] : 'draft', 'description' => (string) $p->description];
                    if ($p->image && str_starts_with((string) $p->image, 'http')) {
                        $body['images'] = [['src' => $p->image]];
                    }
                    if ($p->category) {
                        $body['categories'] = [['id' => $this->categoryId($store, $client, $p->category->name)]];
                    }
                }
            }
            $h ??= md5(json_encode($body));
            if ($l->remote_id && $l->hash === $h && ! $force) {
                continue;
            }
            $hashes[$l->id] = $h;
            if ($l->entity === 'variation') {
                $variations[$l->remote_parent_id][] = ['id' => $l->remote_id] + $body + ['_link' => $l->id];
            } elseif ($l->remote_id) {
                $update[] = ['id' => $l->remote_id] + $body + ['_link' => $l->id];
            } else {
                $create[] = array_filter($body, fn ($v) => $v !== null) + ['_link' => $l->id];
            }
        }
        $res = ['created' => 0, 'updated' => 0, 'errors' => 0];
        $apply = function (string $path, array $items, string $kind) use ($client, $store, $hashes, &$res) {
            foreach (array_chunk($items, 50) as $chunk) {
                $ids = array_column($chunk, '_link');
                $payload = array_map(function ($i) {
                    unset($i['_link']);

                    return $i;
                }, $chunk);
                try {
                    $out = $client->post($path, [$kind => $payload])[$kind] ?? [];
                } catch (WooException $e) {
                    DB::table('woo_links')->whereIn('id', $ids)->update(['error' => Str::limit($e->getMessage(), 295, ''), 'updated_at' => now()]);
                    $res['errors'] += count($ids);
                    $this->log($store, 'Envoi des produits refusé : '.$e->getMessage(), 'error');

                    continue;
                }
                foreach ($out as $k => $o) {
                    $lid = $ids[$k] ?? null;
                    if (! $lid) {
                        continue;
                    }
                    if (! empty($o['error'])) {
                        $msg = strip_tags(html_entity_decode((string) ($o['error']['message'] ?? 'Erreur')));
                        // Référence déjà utilisée sur le site : on relie au produit existant
                        if ($kind === 'create' && ($o['error']['code'] ?? '') === 'product_invalid_sku' && ($rid = $o['error']['data']['resource_id'] ?? null)) {
                            DB::table('woo_links')->where('id', $lid)->update(['remote_id' => $rid, 'error' => null, 'hash' => null, 'updated_at' => now()]);

                            continue;
                        }
                        DB::table('woo_links')->where('id', $lid)->update(['error' => Str::limit($msg, 295, ''), 'updated_at' => now()]);
                        $res['errors']++;

                        continue;
                    }
                    DB::table('woo_links')->where('id', $lid)->update(array_filter(['remote_id' => $kind === 'create' ? ($o['id'] ?? null) : null], fn ($v) => $v !== null)
                        + ['hash' => $hashes[$lid] ?? null, 'error' => null, 'synced_at' => now(), 'updated_at' => now()]);
                    $res[$kind === 'create' ? 'created' : 'updated']++;
                }
            }
        };
        if ($create) {
            $apply('products/batch', $create, 'create');
        }
        if ($update) {
            $apply('products/batch', $update, 'update');
        }
        foreach ($variations as $parent => $items) {
            $apply('products/'.$parent.'/variations/batch', $items, 'update');
        }
        DB::table('woo_stores')->where('id', $store->id)->update(['last_products_sync_at' => now()]);
        if ($res['created'] || $res['updated'] || $res['errors']) {
            $this->log($store, 'Produits : '.$res['created'].' créé(s), '.$res['updated'].' mis à jour'.($res['errors'] ? ', '.$res['errors'].' en erreur' : '').'.', $res['errors'] ? 'warning' : 'info');
        }

        return $res;
    }

    protected function categoryId(object $store, WooClient $client, string $name): ?int
    {
        $map = (array) ($this->info($store)['categories'] ?? []);
        $key = mb_strtolower(trim($name));
        if (isset($map[$key])) {
            return $map[$key];
        }
        try {
            $found = collect($client->get('products/categories', ['search' => $name, 'per_page' => 50]))->first(fn ($x) => mb_strtolower(html_entity_decode($x['name'])) === $key);
            $id = $found['id'] ?? ($client->post('products/categories', ['name' => $name])['id'] ?? null);
        } catch (WooException) {
            $id = null;
        }
        if ($id) {
            $map[$key] = (int) $id;
            $this->setInfo($store, ['categories' => $map]);
        }

        return $id ? (int) $id : null;
    }

    /**
     * Relie les produits déjà présents sur le site à ceux de l'ERP par la référence (SKU), variations comprises ;
     * avec $createMissing, crée dans l'ERP les produits du site qui n'y sont pas.
     */
    public function linkBySku(Company $c, object $store, bool $createMissing = false): array
    {
        $client = WooClient::for($store);
        $remote = $client->all('products', ['status' => 'any']);
        $bySku = Product::withoutGlobalScopes()->where('company_id', $c->id)->whereNotNull('sku')->where('sku', '!=', '')->get()->keyBy(fn ($p) => mb_strtolower(trim($p->sku)));
        $linked = DB::table('woo_links')->where('store_id', $store->id)->whereIn('entity', ['product', 'variation'])->whereNotNull('remote_id')->pluck('remote_id')->flip();
        $res = ['linked' => 0, 'created' => 0, 'unmatched' => 0, 'total' => count($remote)];
        $link = function (string $entity, array $r, ?int $parent, ?Product $p) use ($store, &$res, $linked) {
            if (isset($linked[$r['id']]) || ! $p) {
                return;
            }
            DB::table('woo_links')->where('store_id', $store->id)->whereIn('entity', ['product', 'variation'])->where('local_id', $p->id)->delete();
            DB::table('woo_links')->insert(['company_id' => $store->company_id, 'store_id' => $store->id, 'entity' => $entity, 'local_id' => $p->id, 'remote_id' => $r['id'],
                'remote_parent_id' => $parent, 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $res['linked']++;
        };
        foreach ($remote as $r) {
            if (($r['type'] ?? 'simple') === 'variable') {
                foreach (rescue(fn () => $client->all('products/'.$r['id'].'/variations'), [], false) as $v) {
                    $p = $bySku[mb_strtolower(trim((string) ($v['sku'] ?? '')))] ?? null;
                    $p ? $link('variation', $v, (int) $r['id'], $p) : (isset($linked[$v['id']]) ? null : $res['unmatched']++);
                }

                continue;
            }
            $p = $bySku[mb_strtolower(trim((string) ($r['sku'] ?? '')))] ?? null;
            if (! $p && $createMissing && ! isset($linked[$r['id']])) {
                $price = (float) ($r['regular_price'] ?: $r['price'] ?: 0);
                $p = Product::create(['name' => Str::limit(html_entity_decode((string) $r['name']), 190, ''), 'sku' => ($r['sku'] ?? '') ?: null, 'type' => 'product', 'unit' => 'u',
                    'sale_price' => $price, 'tax_rate' => 0, 'track_stock' => (bool) ($r['manage_stock'] ?? false), 'active' => ($r['status'] ?? '') === 'publish',
                    'description' => Str::limit(trim(strip_tags((string) ($r['short_description'] ?: $r['description']))), 2000, ''), 'image' => $r['images'][0]['src'] ?? null]);
                $res['created']++;
            }
            $p ? $link('product', $r, null, $p) : (isset($linked[$r['id']]) ? null : $res['unmatched']++);
        }
        $this->log($store, 'Rapprochement par référence : '.$res['linked'].' produit(s) relié(s)'.($res['created'] ? ', '.$res['created'].' créé(s) dans l\'ERP' : '').', '.$res['unmatched'].' sans correspondance.');

        return $res;
    }

    // ------------------------------------------------------------------ commandes

    /** Relevé des commandes modifiées depuis le dernier passage (secours du webhook, et import initial). */
    public function pullOrders(Company $c, object $store): int
    {
        $set = $this->settings($store);
        if (! $set['import_orders']) {
            return 0;
        }
        $since = $store->last_orders_sync_at ? Carbon::parse($store->last_orders_sync_at)->subMinutes(10) : now()->subDays(7);
        $started = now();
        $orders = WooClient::for($store)->all('orders', ['modified_after' => $since->utc()->format('Y-m-d\TH:i:s'), 'orderby' => 'modified', 'order' => 'asc'], 10);
        $n = 0;
        foreach ($orders as $o) {
            $n += $this->importOrder($c, $store, $o) ? 1 : 0;
        }
        DB::table('woo_stores')->where('id', $store->id)->update(['last_orders_sync_at' => $started, 'status' => 'active', 'error' => null]);

        return $n;
    }

    /** Webhook WooCommerce (signature HMAC-SHA256 en base64 du corps brut). */
    public function webhook(object $store, string $raw, ?string $signature, ?string $topic): bool
    {
        if (! $signature || ! hash_equals(base64_encode(hash_hmac('sha256', $raw, $store->webhook_secret, true)), $signature)) {
            return false;
        }
        DB::table('woo_stores')->where('id', $store->id)->update(['last_webhook_at' => now()]);
        $o = json_decode($raw, true);
        if (str_starts_with((string) $topic, 'order.') && is_array($o) && isset($o['id'], $o['line_items'])) {
            $c = Company::find($store->company_id);
            app()->instance('tenant', $c);
            if ($c->hasModule(static::MODULE)) {
                $this->importOrder($c, $store, $o);
            }
        }

        return true;
    }

    /** Crée ou met à jour la commande de l'ERP ; renvoie true si quelque chose a changé. */
    public function importOrder(Company $c, object $store, array $o): bool
    {
        $set = $this->settings($store);
        $status = (string) ($o['status'] ?? '');
        $link = DB::table('woo_links')->where('store_id', $store->id)->where('entity', 'order')->where('remote_id', $o['id'])->first();
        if (! $link && (! $set['import_orders'] || ! in_array($status, (array) $set['import_statuses'], true))) {
            return false;
        }

        return DB::transaction(function () use ($c, $store, $o, $status, $link, $set) {
            $meta = ['number' => (string) ($o['number'] ?? $o['id']), 'status' => $status, 'payment_method' => $o['payment_method'] ?? null, 'payment_title' => $o['payment_method_title'] ?? null,
                'total' => (float) ($o['total'] ?? 0), 'currency' => $o['currency'] ?? null, 'customer' => trim(($o['billing']['first_name'] ?? '').' '.($o['billing']['last_name'] ?? '')) ?: ($o['billing']['company'] ?? null),
                'date' => $o['date_created'] ?? null, 'date_paid' => $o['date_paid'] ?? null, 'transaction_id' => $o['transaction_id'] ?? null];
            if (! $link) {
                $docId = $this->createOrderDocument($c, $store, $o, $set);
                $linkId = DB::table('woo_links')->insertGetId(['company_id' => $c->id, 'store_id' => $store->id, 'entity' => 'order', 'local_id' => $docId, 'remote_id' => $o['id'],
                    'meta' => json_encode($meta), 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $link = DB::table('woo_links')->find($linkId);
                $this->log($store, 'Commande n° '.$meta['number'].' importée ('.(self::STATUSES[$status] ?? $status).', '.number_format($meta['total'], 0, ',', ' ').' '.($meta['currency'] ?? '').').');
                $changed = true;
            } else {
                $old = (array) json_decode((string) $link->meta, true);
                $changed = ($old['status'] ?? null) !== $status || ($old['date_paid'] ?? null) !== $meta['date_paid'];
                DB::table('woo_links')->where('id', $link->id)->update(['meta' => json_encode($meta + array_intersect_key($old, ['pushed' => 1])), 'synced_at' => now(), 'updated_at' => now()]);
                if (! $changed) {
                    return false;
                }
            }
            $this->applyStatus($c, $store, $link, $o, $set);

            return $changed;
        });
    }

    protected function createOrderDocument(Company $c, object $store, array $o, array $set): string
    {
        $b = (array) ($o['billing'] ?? []);
        $s = (array) ($o['shipping'] ?? []);
        $partner = $this->partner($b, $store);
        $productLinks = DB::table('woo_links')->where('store_id', $store->id)->whereIn('entity', ['product', 'variation'])->whereNotNull('remote_id')->pluck('local_id', 'remote_id');
        $skus = collect($o['line_items'] ?? [])->pluck('sku')->filter()->map(fn ($x) => mb_strtolower(trim($x)))->all();
        $bySku = $skus ? Product::whereNotNull('sku')->get(['id', 'sku'])->filter(fn ($p) => in_array(mb_strtolower(trim($p->sku)), $skus, true))->keyBy(fn ($p) => mb_strtolower(trim($p->sku))) : collect();
        $lines = [];
        $rate = fn (float $total, float $tax) => $total > 0 && $tax > 0 ? round($tax * 100 / $total, 2) : 0.0;
        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $qty = (float) ($li['quantity'] ?? 1) ?: 1;
            $total = (float) ($li['total'] ?? 0);
            $pid = $productLinks[$li['variation_id'] ?? 0] ?? $productLinks[$li['product_id'] ?? 0] ?? ($bySku[mb_strtolower(trim((string) ($li['sku'] ?? '')))]->id ?? null);
            $lines[] = ['id' => (string) Str::uuid(), 'product_id' => $pid, 'description' => Str::limit(html_entity_decode((string) $li['name']), 250, ''), 'quantity' => $qty,
                'unit_price' => round($total / $qty, 4), 'tax_rate' => $rate($total, (float) ($li['total_tax'] ?? 0)), 'discount_percent' => 0];
        }
        foreach ((array) ($o['shipping_lines'] ?? []) as $sl) {
            if ((float) $sl['total'] > 0) {
                $lines[] = ['id' => (string) Str::uuid(), 'description' => Str::limit('Livraison — '.($sl['method_title'] ?? ''), 250, ''), 'quantity' => 1, 'unit_price' => (float) $sl['total'],
                    'tax_rate' => $rate((float) $sl['total'], (float) ($sl['total_tax'] ?? 0)), 'discount_percent' => 0];
            }
        }
        foreach ((array) ($o['fee_lines'] ?? []) as $fl) {
            if ((float) $fl['total'] != 0) {
                $lines[] = ['id' => (string) Str::uuid(), 'description' => Str::limit((string) ($fl['name'] ?? 'Frais'), 250, ''), 'quantity' => 1, 'unit_price' => (float) $fl['total'],
                    'tax_rate' => $rate((float) $fl['total'], (float) ($fl['total_tax'] ?? 0)), 'discount_percent' => 0];
            }
        }
        if (! $lines) {
            throw new WooException('Commande sans article.');
        }
        $addr = trim(implode(', ', array_filter([$s['address_1'] ?? null, $s['address_2'] ?? null, $s['city'] ?? null]))) ?: trim(implode(', ', array_filter([$b['address_1'] ?? null, $b['city'] ?? null])));
        $coupons = collect($o['coupon_lines'] ?? [])->pluck('code')->filter()->implode(', ');
        $id = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesDocument::class, $id, array_filter([
                'type' => 'order', 'partner_id' => $partner->id, 'date' => Carbon::parse($o['date_created'] ?? now())->toDateString(), 'status' => 'validated',
                'reference' => Str::limit($set['order_prefix'].'-'.($o['number'] ?? $o['id']), 60, ''), 'warehouse_id' => $set['warehouse_id'] ?: null,
                'notes' => trim('Commande '.static::LABEL.' n° '.($o['number'] ?? $o['id']).' ('.$store->name.')'.' · '.($o['payment_method_title'] ?? '')
                    .($addr ? "\nLivraison : ".$addr : '').($coupons ? "\nCode promo : ".$coupons : '').(! empty($o['customer_note']) ? "\nNote du client : ".Str::limit($o['customer_note'], 500, '') : '')),
                'lines' => $lines,
            ], fn ($v) => $v !== null));
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new WooException('Commande refusée par l\'ERP : '.($res['message'] ?? 'erreur inconnue'));
        }

        return $id;
    }

    protected function partner(array $b, object $store): Partner
    {
        $email = filter_var($b['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null;
        $phone = trim((string) ($b['phone'] ?? '')) ?: null;
        $digits = substr(preg_replace('/\D/', '', (string) $phone), -8);
        $p = $email ? Partner::whereRaw('lower(email) = ?', [mb_strtolower($email)])->first() : null;
        if (! $p && strlen($digits) === 8) {
            $p = Partner::whereNotNull('phone')->where('phone', 'like', '%'.substr($digits, -4))->get()->first(fn ($x) => substr(preg_replace('/\D/', '', (string) $x->phone), -8) === $digits);
        }
        $name = trim(($b['first_name'] ?? '').' '.($b['last_name'] ?? '')) ?: trim((string) ($b['company'] ?? '')) ?: ($email ?? 'Client '.static::LABEL);

        return $p ?? Partner::create(['name' => Str::limit(! empty($b['company']) ? $b['company'] : $name, 160, ''), 'type' => 'customer', 'is_company' => ! empty($b['company']),
            'phone' => $phone, 'email' => $email, 'address' => Str::limit(trim(implode(', ', array_filter([$b['address_1'] ?? null, $b['address_2'] ?? null]))), 250, '') ?: null,
            'city' => ($b['city'] ?? '') ?: null, 'notes' => 'Client créé par une commande '.static::LABEL.' ('.$store->name.')']);
    }

    /** Suite donnée au statut de la commande du site : facture et encaissement si payée, annulation si annulée. */
    protected function applyStatus(Company $c, object $store, object $link, array $o, array $set): void
    {
        $status = (string) $o['status'];
        $doc = SalesDocument::find($link->local_id);
        if (! $doc) {
            return;
        }
        $invoice = SalesDocument::where('source_id', $doc->id)->where('type', 'invoice')->where('status', '!=', 'cancelled')->first();
        if (in_array($status, ['cancelled', 'refunded', 'failed'], true)) {
            if ($invoice) {
                $this->log($store, 'Commande n° '.($o['number'] ?? $o['id']).' '.mb_strtolower(self::STATUSES[$status]).' sur le site mais déjà facturée ('.$invoice->number.') : faites un avoir dans les Ventes.', 'warning');
            } elseif ($doc->status !== 'cancelled') {
                SalesDocument::$systemWrite = true;
                try {
                    $this->writer->upsert(SalesDocument::class, $doc->id, ['status' => 'cancelled']);
                } finally {
                    SalesDocument::$systemWrite = false;
                }
                $this->log($store, 'Commande n° '.($o['number'] ?? $o['id']).' annulée (statut du site : '.mb_strtolower(self::STATUSES[$status]).').');
            }

            return;
        }
        if (! $set['invoice_paid']) {
            return;
        }
        $paidOnline = ! empty($o['date_paid']) && ($o['payment_method'] ?? '') !== 'cod';
        if (! $paidOnline && $status !== 'completed') {
            return;
        }
        $invoice ??= $this->invoiceFrom($doc);
        if ($invoice->payment_status !== 'paid' && $invoice->balance() > 0) {
            self::$importing = true;
            SalesPayment::$systemWrite = true;
            try {
                $this->writer->upsert(SalesPayment::class, (string) Str::uuid(), array_filter([
                    'document_id' => $invoice->id, 'date' => Carbon::parse($o['date_paid'] ?? $o['date_completed'] ?? now())->toDateString(), 'amount' => $invoice->balance(),
                    'method' => self::METHODS[$o['payment_method'] ?? ''] ?? (str_contains((string) ($o['payment_method'] ?? ''), 'momo') || str_contains(mb_strtolower((string) ($o['payment_method_title'] ?? '')), 'mobile') ? 'mobile_money' : 'card'),
                    'reference' => Str::limit(($o['transaction_id'] ?? '') ?: static::LABEL.' '.($o['number'] ?? $o['id']), 80, ''), 'notes' => 'Paiement '.static::LABEL.' : '.($o['payment_method_title'] ?? ''),
                ], fn ($v) => $v !== null && $v !== ''));
            } finally {
                SalesPayment::$systemWrite = false;
                self::$importing = false;
            }
        }
    }

    /** Facture de la commande client (fait sortir le stock). */
    protected function invoiceFrom(SalesDocument $doc): SalesDocument
    {
        $doc->loadMissing('lines');
        $id = (string) Str::uuid();
        SalesDocument::$systemWrite = true;
        try {
            $res = $this->writer->upsert(SalesDocument::class, $id, [
                'type' => 'invoice', 'partner_id' => $doc->partner_id, 'date' => now()->toDateString(), 'due_date' => now()->toDateString(), 'status' => 'validated',
                'reference' => $doc->reference, 'source_id' => $doc->id, 'warehouse_id' => $doc->warehouse_id, 'branch_id' => $doc->branch_id, 'notes' => $doc->notes,
                'lines' => $doc->lines->map(fn ($l) => ['id' => (string) Str::uuid()] + $l->only(['product_id', 'description', 'quantity', 'unit', 'unit_price', 'discount_percent', 'tax_rate']))->all(),
            ]);
        } finally {
            SalesDocument::$systemWrite = false;
        }
        if ($res['status'] !== EntityWriter::APPLIED) {
            throw new WooException('Facturation refusée : '.($res['message'] ?? 'erreur inconnue'));
        }

        return SalesDocument::find($id);
    }

    /** Facture payée dans l'ERP (paiement à la livraison, virement reçu) : le statut revient sur le site. */
    public function onEvent(int $companyId, string $event, \Illuminate\Database\Eloquent\Model $m): void
    {
        if ($event !== 'invoice.paid' || self::$importing || ! \Illuminate\Support\Facades\Schema::hasTable('woo_links')) {
            return;
        }
        $link = DB::table('woo_links')->where('company_id', $companyId)->where('entity', 'order')->whereIn('local_id', array_filter([$m->source_id, $m->id]))->first();
        if (! $link || ! ($store = DB::table('woo_stores')->find($link->store_id)) || ($store->platform ?? 'woocommerce') !== static::PLATFORM || ! $this->settings($store)['push_status']) {
            return;
        }
        $meta = (array) json_decode((string) $link->meta, true);
        $target = match (true) {
            ($meta['payment_method'] ?? null) === 'cod' && in_array($meta['status'] ?? '', ['processing', 'on-hold'], true) => 'completed',
            ($meta['status'] ?? '') === 'on-hold' => 'processing',
            default => null,
        };
        if ($target) {
            $this->setRemoteStatus($store, $link, $target);
        }
    }

    public function setRemoteStatus(object $store, object $link, string $status): void
    {
        WooClient::for($store)->put('orders/'.$link->remote_id, ['status' => $status]);
        $meta = (array) json_decode((string) $link->meta, true);
        DB::table('woo_links')->where('id', $link->id)->update(['meta' => json_encode(['status' => $status, 'pushed' => now()->toIso8601String()] + $meta), 'updated_at' => now()]);
        $this->log($store, 'Commande n° '.($meta['number'] ?? $link->remote_id).' passée à « '.(self::STATUSES[$status] ?? $status).' » sur le site.');
    }

    // ------------------------------------------------------------------ tâche planifiée

    public function runDue(): array
    {
        $out = ['stores' => 0, 'orders' => 0, 'products' => 0];
        foreach (DB::table('woo_stores')->where('status', '!=', 'paused')->where('platform', static::PLATFORM)->get() as $store) {
            $c = Company::find($store->company_id);
            if (! $c || ! $c->hasModule(static::MODULE) || ! empty($this->info($store)['demo'])) {
                continue; // boutique de démonstration : aucun appel au site
            }
            app()->instance('tenant', $c);
            $out['stores']++;
            try {
                $out['orders'] += $this->pullOrders($c, $store);
                $store = DB::table('woo_stores')->find($store->id);
                $r = $this->pushProducts($c, $store);
                $out['products'] += $r['created'] + $r['updated'];
                if ($store->status === 'error') {
                    DB::table('woo_stores')->where('id', $store->id)->update(['status' => 'active', 'error' => null]);
                }
            } catch (\Throwable $e) {
                DB::table('woo_stores')->where('id', $store->id)->update(['status' => 'error', 'error' => Str::limit($e->getMessage(), 495, ''), 'updated_at' => now()]);
                if ($store->error !== Str::limit($e->getMessage(), 495, '')) {
                    $this->log($store, $e->getMessage(), 'error');
                }
            }
        }

        return $out;
    }
}
