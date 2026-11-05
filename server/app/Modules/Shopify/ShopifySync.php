<?php

namespace App\Modules\Shopify;

use App\Models\Company;
use App\Modules\Core\Models\Product;
use App\Modules\Woocommerce\WooException;
use App\Modules\Woocommerce\WooSync;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Connecteur Shopify, sur le même socle que WooCommerce (tables woo_*, import des commandes, facturation, stock
 * disponible). Particularités : chaque produit est une variante (prix, stock par emplacement), commandes normalisées
 * au format WooCommerce, webhooks signés avec la clé secrète de l'application, « marquer comme payée » par GraphQL.
 */
class ShopifySync extends WooSync
{
    public const PLATFORM = 'shopify';

    public const MODULE = 'shopify';

    public const LABEL = 'Shopify';

    public function connect(Company $c, array $d): object
    {
        $shop = ShopifyClient::domain($d['url']);
        $client = new ShopifyClient($shop, trim($d['consumer_key']));
        $info = $client->json('GET', 'shop.json')['shop'] ?? [];
        if (DB::table('woo_stores')->where('company_id', $c->id)->where('url', 'https://'.$shop)->exists()) {
            throw new WooException('Cette boutique est déjà connectée.');
        }
        $id = DB::table('woo_stores')->insertGetId(['company_id' => $c->id, 'platform' => 'shopify', 'name' => Str::limit(trim($d['name'] ?? '') ?: ($info['name'] ?? $shop), 120, ''),
            'url' => 'https://'.$shop, 'credentials' => Crypt::encryptString(json_encode(['key' => trim($d['consumer_key'])])), 'query_auth' => false,
            'token' => Str::lower(Str::random(40)), 'webhook_secret' => trim($d['consumer_secret']), 'status' => 'active', 'settings' => json_encode(self::DEFAULTS),
            'last_orders_sync_at' => now()->subDays(max(0, min(60, (int) ($d['import_days'] ?? 7)))), 'created_at' => now(), 'updated_at' => now()]);
        $store = DB::table('woo_stores')->find($id);
        $this->refreshInfo($store);
        $this->ensureWebhooks($store);
        $this->log($store, 'Boutique Shopify connectée ('.$shop.').');

        return DB::table('woo_stores')->find($id);
    }

    public function refreshInfo(object $store): void
    {
        $client = ShopifyClient::for($store);
        $shop = $client->json('GET', 'shop.json')['shop'] ?? [];
        $loc = collect(rescue(fn () => $client->json('GET', 'locations.json')['locations'] ?? [], [], false))->firstWhere('active', true);
        $this->setInfo($store, ['currency' => $shop['currency'] ?? null, 'prices_include_tax' => (bool) ($shop['taxes_included'] ?? true), 'calc_taxes' => true,
            'version' => 'Shopify '.ShopifyClient::VERSION, 'site_title' => $shop['name'] ?? null, 'location_id' => $shop['primary_location_id'] ?? ($loc['id'] ?? null),
            'domain' => $shop['domain'] ?? null]);
    }

    public function ensureWebhooks(object $store): void
    {
        $client = ShopifyClient::for($store);
        $url = url('/api/shopify-webhook/'.$store->token);
        try {
            $existing = collect($client->json('GET', 'webhooks.json')['webhooks'] ?? [])->where('address', $url)->keyBy('topic');
            $hooks = [];
            foreach (['orders/create', 'orders/updated'] as $topic) {
                $h = $existing[$topic] ?? ($client->json('POST', 'webhooks.json', ['webhook' => ['topic' => $topic, 'address' => $url, 'format' => 'json']])['webhook'] ?? []);
                $hooks[$topic] = $h['id'] ?? null;
            }
            $this->setInfo($store, ['webhooks' => $hooks, 'webhook_error' => null]);
        } catch (\Throwable $e) {
            $this->setInfo($store, ['webhook_error' => $e->getMessage()]);
            $this->log($store, 'Webhooks non créés ('.$e->getMessage().') : les commandes seront relevées toutes les 5 minutes.', 'warning');
        }
    }

    public function disconnect(object $store): void
    {
        $client = ShopifyClient::for($store);
        foreach ((array) ($this->info($store)['webhooks'] ?? []) as $hid) {
            if ($hid) {
                rescue(fn () => $client->call('DELETE', 'webhooks/'.$hid.'.json'), null, false);
            }
        }
        DB::table('woo_links')->where('store_id', $store->id)->delete();
        DB::table('woo_logs')->where('store_id', $store->id)->delete();
        DB::table('woo_stores')->where('id', $store->id)->delete();
    }

    protected function unpublishRemote(object $store, object $link): void
    {
        if ($link->remote_parent_id && $link->entity === 'product') {
            rescue(fn () => ShopifyClient::for($store)->json('PUT', 'products/'.$link->remote_parent_id.'.json', ['product' => ['id' => $link->remote_parent_id, 'status' => 'draft']]), null, false);
        }
    }

    // ------------------------------------------------------------------ produits

    public function pushProducts(Company $c, object $store, bool $force = false): array
    {
        $set = $this->settings($store);
        $client = ShopifyClient::for($store);
        $location = $this->info($store)['location_id'] ?? null;
        $links = DB::table('woo_links')->where('store_id', $store->id)->whereIn('entity', ['product', 'variation'])->whereNotNull('local_id')->get();
        $products = Product::withoutGlobalScopes()->where('company_id', $c->id)->whereIn('id', $links->pluck('local_id'))->with('category')->get()->keyBy('id');
        $stock = $this->available($c, $store, $products->where('track_stock', true)->keys()->all());
        $res = ['created' => 0, 'updated' => 0, 'errors' => 0];
        foreach ($links as $l) {
            $p = $products[$l->local_id] ?? null;
            if (! $p) {
                continue;
            }
            $manage = $p->track_stock && $c->hasModule('stock') && $set['publish_stock'];
            $state = ['price' => $set['publish_prices'] ? $this->price($store, $p) : null, 'stock' => $manage ? ($stock[$p->id] ?? 0) : null,
                'title' => $l->entity === 'product' ? $p->name : null, 'active' => $l->entity === 'product' ? (bool) $p->active : null];
            $h = md5(json_encode($state));
            if ($l->remote_id && $l->hash === $h && ! $force) {
                continue;
            }
            try {
                $meta = (array) json_decode((string) $l->meta, true);
                if (! $l->remote_id) {
                    $prod = $client->json('POST', 'products.json', ['product' => array_filter([
                        'title' => $p->name, 'body_html' => $p->description ? nl2br(e($p->description)) : null, 'status' => $p->active && $set['publish_new_as'] === 'publish' ? 'active' : 'draft',
                        'product_type' => $p->category?->name, 'images' => $p->image && str_starts_with((string) $p->image, 'http') ? [['src' => $p->image]] : null,
                        'variants' => [array_filter(['price' => $state['price'] ?? $this->price($store, $p), 'sku' => $p->sku ?: null, 'inventory_management' => $manage ? 'shopify' : null], fn ($v) => $v !== null)],
                    ], fn ($v) => $v !== null)])['product'] ?? [];
                    $v = $prod['variants'][0] ?? [];
                    $meta['inventory_item_id'] = $v['inventory_item_id'] ?? null;
                    DB::table('woo_links')->where('id', $l->id)->update(['remote_id' => $v['id'] ?? null, 'remote_parent_id' => $prod['id'] ?? null, 'meta' => json_encode($meta)]);
                    $res['created']++;
                } else {
                    if ($state['price'] !== null) {
                        $client->json('PUT', 'variants/'.$l->remote_id.'.json', ['variant' => ['id' => $l->remote_id, 'price' => $state['price']] + ($manage ? ['inventory_management' => 'shopify'] : [])]);
                    }
                    if ($l->entity === 'product' && $l->remote_parent_id) {
                        $client->json('PUT', 'products/'.$l->remote_parent_id.'.json', ['product' => ['id' => $l->remote_parent_id, 'title' => $p->name] + ($p->active ? [] : ['status' => 'draft'])]);
                    }
                    $res['updated']++;
                }
                $inv = $meta['inventory_item_id'] ?? null;
                if ($manage && $inv && $location) {
                    $client->json('POST', 'inventory_levels/set.json', ['location_id' => $location, 'inventory_item_id' => $inv, 'available' => (int) $state['stock']]);
                }
                DB::table('woo_links')->where('id', $l->id)->update(['hash' => $h, 'error' => null, 'synced_at' => now(), 'updated_at' => now()]);
            } catch (WooException $e) {
                DB::table('woo_links')->where('id', $l->id)->update(['error' => Str::limit($e->getMessage(), 295, ''), 'updated_at' => now()]);
                $res['errors']++;
            }
        }
        DB::table('woo_stores')->where('id', $store->id)->update(['last_products_sync_at' => now()]);
        if ($res['created'] || $res['updated'] || $res['errors']) {
            $this->log($store, 'Produits : '.$res['created'].' créé(s), '.$res['updated'].' mis à jour'.($res['errors'] ? ', '.$res['errors'].' en erreur' : '').'.', $res['errors'] ? 'warning' : 'info');
        }

        return $res;
    }

    public function linkBySku(Company $c, object $store, bool $createMissing = false): array
    {
        $remote = ShopifyClient::for($store)->all('products.json', 'products');
        $bySku = Product::withoutGlobalScopes()->where('company_id', $c->id)->whereNotNull('sku')->where('sku', '!=', '')->get()->keyBy(fn ($p) => mb_strtolower(trim($p->sku)));
        $linked = DB::table('woo_links')->where('store_id', $store->id)->whereIn('entity', ['product', 'variation'])->whereNotNull('remote_id')->pluck('remote_id')->flip();
        $res = ['linked' => 0, 'created' => 0, 'unmatched' => 0, 'total' => count($remote)];
        foreach ($remote as $r) {
            foreach ((array) ($r['variants'] ?? []) as $v) {
                if (isset($linked[$v['id']])) {
                    continue;
                }
                $p = $bySku[mb_strtolower(trim((string) ($v['sku'] ?? '')))] ?? null;
                $entity = 'variation';
                if (! $p && $createMissing && count($r['variants']) === 1) {
                    $p = Product::create(['name' => Str::limit((string) $r['title'], 190, ''), 'sku' => ($v['sku'] ?? '') ?: null, 'type' => 'product', 'unit' => 'u', 'sale_price' => (float) $v['price'],
                        'tax_rate' => 0, 'track_stock' => ($v['inventory_management'] ?? null) === 'shopify', 'active' => ($r['status'] ?? '') === 'active',
                        'description' => Str::limit(trim(strip_tags((string) ($r['body_html'] ?? ''))), 2000, ''), 'image' => $r['image']['src'] ?? null]);
                    $entity = 'product';
                    $res['created']++;
                }
                if (! $p) {
                    $res['unmatched']++;

                    continue;
                }
                DB::table('woo_links')->where('store_id', $store->id)->whereIn('entity', ['product', 'variation'])->where('local_id', $p->id)->delete();
                DB::table('woo_links')->insert(['company_id' => $store->company_id, 'store_id' => $store->id, 'entity' => $entity, 'local_id' => $p->id, 'remote_id' => $v['id'],
                    'remote_parent_id' => $r['id'], 'meta' => json_encode(['inventory_item_id' => $v['inventory_item_id'] ?? null]), 'synced_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $res['linked']++;
            }
        }
        $this->log($store, 'Rapprochement par référence : '.$res['linked'].' produit(s) relié(s)'.($res['created'] ? ', '.$res['created'].' créé(s) dans l\'ERP' : '').', '.$res['unmatched'].' sans correspondance.');

        return $res;
    }

    // ------------------------------------------------------------------ commandes

    public function pullOrders(Company $c, object $store): int
    {
        if (! $this->settings($store)['import_orders']) {
            return 0;
        }
        $since = $store->last_orders_sync_at ? Carbon::parse($store->last_orders_sync_at)->subMinutes(10) : now()->subDays(7);
        $started = now();
        $orders = ShopifyClient::for($store)->all('orders.json', 'orders', ['status' => 'any', 'updated_at_min' => $since->utc()->toIso8601String(), 'order' => 'updated_at asc'], 10);
        $n = 0;
        foreach ($orders as $o) {
            $n += $this->importOrder($c, $store, self::normalize($o)) ? 1 : 0;
        }
        DB::table('woo_stores')->where('id', $store->id)->update(['last_orders_sync_at' => $started, 'status' => 'active', 'error' => null]);

        return $n;
    }

    public function webhook(object $store, string $raw, ?string $signature, ?string $topic): bool
    {
        if (! $signature || ! hash_equals(base64_encode(hash_hmac('sha256', $raw, $store->webhook_secret, true)), $signature)) {
            return false;
        }
        DB::table('woo_stores')->where('id', $store->id)->update(['last_webhook_at' => now()]);
        $o = json_decode($raw, true);
        if (str_starts_with((string) $topic, 'orders/') && is_array($o) && isset($o['id'], $o['line_items'])) {
            $c = Company::find($store->company_id);
            app()->instance('tenant', $c);
            if ($c->hasModule(static::MODULE)) {
                $this->importOrder($c, $store, self::normalize($o));
            }
        }

        return true;
    }

    /** Commande Shopify → format commun (celui de WooCommerce : montants HT et taxe séparés, statut, paiement). */
    public static function normalize(array $o): array
    {
        $incl = (bool) ($o['taxes_included'] ?? false);
        $gw = mb_strtolower(implode(' ', (array) ($o['payment_gateway_names'] ?? [])));
        $method = match (true) {
            str_contains($gw, 'cash on delivery') || str_contains($gw, 'cod') || str_contains($gw, 'livraison') => 'cod',
            str_contains($gw, 'bank') || str_contains($gw, 'virement') || str_contains($gw, 'manual') || $gw === '' => 'bacs',
            default => Str::slug($gw),
        };
        $fin = (string) ($o['financial_status'] ?? '');
        $paid = in_array($fin, ['paid', 'partially_refunded'], true);
        $status = match (true) {
            ! empty($o['cancelled_at']) => 'cancelled',
            $fin === 'refunded' => 'refunded',
            ($o['fulfillment_status'] ?? null) === 'fulfilled' || ! empty($o['closed_at']) => 'completed',
            $paid || $method === 'cod' || $fin === 'authorized' => 'processing',
            default => 'on-hold',
        };
        $tax = fn (array $x) => collect($x['tax_lines'] ?? [])->sum(fn ($t) => (float) $t['price']);
        $lines = [];
        foreach ((array) ($o['line_items'] ?? []) as $li) {
            $disc = collect($li['discount_allocations'] ?? [])->sum(fn ($d) => (float) $d['amount']) ?: (float) ($li['total_discount'] ?? 0);
            $t = $tax($li);
            $lines[] = ['product_id' => 0, 'variation_id' => (int) ($li['variant_id'] ?? 0), 'name' => trim(($li['title'] ?? '').(! empty($li['variant_title']) ? ' — '.$li['variant_title'] : '')),
                'sku' => $li['sku'] ?? null, 'quantity' => (float) $li['quantity'], 'total' => (string) round((float) $li['price'] * (float) $li['quantity'] - $disc - ($incl ? $t : 0), 2), 'total_tax' => (string) $t];
        }
        $ship = [];
        foreach ((array) ($o['shipping_lines'] ?? []) as $sl) {
            $t = $tax($sl);
            $ship[] = ['method_title' => $sl['title'] ?? 'Livraison', 'total' => (string) round((float) ($sl['discounted_price'] ?? $sl['price']) - ($incl ? $t : 0), 2), 'total_tax' => (string) $t];
        }
        $ba = (array) ($o['billing_address'] ?? $o['shipping_address'] ?? []);
        $sa = (array) ($o['shipping_address'] ?? []);

        return [
            'id' => $o['id'], 'number' => (string) ($o['order_number'] ?? ltrim((string) ($o['name'] ?? ''), '#')), 'status' => $status, 'currency' => $o['currency'] ?? null,
            'total' => (string) ($o['total_price'] ?? 0), 'date_created' => $o['created_at'] ?? null, 'date_paid' => $paid ? ($o['processed_at'] ?? $o['updated_at'] ?? null) : null,
            'date_completed' => $o['closed_at'] ?? null, 'payment_method' => $method, 'payment_method_title' => implode(', ', (array) ($o['payment_gateway_names'] ?? [])) ?: 'Paiement manuel',
            'transaction_id' => '', 'customer_note' => $o['note'] ?? '',
            'billing' => ['first_name' => $ba['first_name'] ?? ($o['customer']['first_name'] ?? ''), 'last_name' => $ba['last_name'] ?? ($o['customer']['last_name'] ?? ''), 'company' => $ba['company'] ?? '',
                'email' => $o['email'] ?? ($o['customer']['email'] ?? null), 'phone' => $o['phone'] ?? ($ba['phone'] ?? ($o['customer']['phone'] ?? null)),
                'address_1' => $ba['address1'] ?? null, 'address_2' => $ba['address2'] ?? null, 'city' => $ba['city'] ?? null],
            'shipping' => ['address_1' => $sa['address1'] ?? null, 'address_2' => $sa['address2'] ?? null, 'city' => $sa['city'] ?? null],
            'line_items' => $lines, 'shipping_lines' => $ship, 'fee_lines' => [],
            'coupon_lines' => collect($o['discount_codes'] ?? [])->map(fn ($d) => ['code' => $d['code'] ?? ''])->all(),
        ];
    }

    /** Encaissement dans l'ERP : la commande est marquée payée sur Shopify. */
    public function onEvent(int $companyId, string $event, \Illuminate\Database\Eloquent\Model $m): void
    {
        if ($event !== 'invoice.paid' || self::$importing || ! \Illuminate\Support\Facades\Schema::hasTable('woo_links')) {
            return;
        }
        $link = DB::table('woo_links')->where('company_id', $companyId)->where('entity', 'order')->whereIn('local_id', array_filter([$m->source_id, $m->id]))->first();
        if (! $link || ! ($store = DB::table('woo_stores')->find($link->store_id)) || $store->platform !== 'shopify' || ! $this->settings($store)['push_status']) {
            return;
        }
        $meta = (array) json_decode((string) $link->meta, true);
        if (empty($meta['date_paid']) && in_array($meta['status'] ?? '', ['processing', 'on-hold'], true)) {
            $this->setRemoteStatus($store, $link, 'paid');
        }
    }

    public function setRemoteStatus(object $store, object $link, string $status): void
    {
        $client = ShopifyClient::for($store);
        if ($status === 'completed') {
            $client->json('POST', 'orders/'.$link->remote_id.'/close.json');
        } elseif (in_array($status, ['paid', 'processing'], true)) {
            $r = $client->graphql('mutation($input: OrderMarkAsPaidInput!) { orderMarkAsPaid(input: $input) { userErrors { message } } }', ['input' => ['id' => 'gid://shopify/Order/'.$link->remote_id]]);
            if ($err = $r['orderMarkAsPaid']['userErrors'][0]['message'] ?? null) {
                throw new WooException('Shopify : '.$err);
            }
            $status = 'processing';
        } elseif ($status === 'cancelled') {
            $client->json('POST', 'orders/'.$link->remote_id.'/cancel.json');
        }
        $meta = (array) json_decode((string) $link->meta, true);
        DB::table('woo_links')->where('id', $link->id)->update(['meta' => json_encode(['status' => $status, 'date_paid' => $meta['date_paid'] ?? ($status === 'processing' ? now()->toIso8601String() : null),
            'pushed' => now()->toIso8601String()] + $meta), 'updated_at' => now()]);
        $this->log($store, 'Commande n° '.($meta['number'] ?? $link->remote_id).($status === 'processing' ? ' marquée payée' : ' passée à « '.(self::STATUSES[$status] ?? $status).' »').' sur Shopify.');
    }
}
