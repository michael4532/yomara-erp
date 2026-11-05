<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Connecteur Shopify : connexion, produits (variantes, stock par emplacement), commandes normalisées, webhook signé, marquer payée. */
class ShopifyTest extends TestCase
{
    protected function order(int $id, array $extra = []): array
    {
        return array_replace([
            'id' => $id, 'order_number' => 1001, 'name' => '#1001', 'currency' => 'XOF', 'total_price' => '21800', 'taxes_included' => true, 'financial_status' => 'pending',
            'fulfillment_status' => null, 'cancelled_at' => null, 'closed_at' => null, 'created_at' => now()->subHour()->toIso8601String(), 'processed_at' => now()->subHour()->toIso8601String(),
            'payment_gateway_names' => ['Cash on Delivery (COD)'], 'email' => 'yao@gmail.com', 'phone' => null, 'note' => 'Sonner deux fois',
            'billing_address' => ['first_name' => 'Yao', 'last_name' => 'Kouassi', 'address1' => 'Cocody', 'city' => 'Abidjan', 'phone' => '0505050505'],
            'shipping_address' => ['address1' => 'Cocody Danga', 'city' => 'Abidjan'],
            'line_items' => [['variant_id' => 801, 'title' => 'Ciment', 'variant_title' => null, 'sku' => 'CIM45', 'quantity' => 3, 'price' => '5900', 'total_discount' => '0',
                'discount_allocations' => [['amount' => '900']], 'tax_lines' => [['price' => '2563.56']]]],
            'shipping_lines' => [['title' => 'Livraison Abidjan', 'price' => '1000', 'discounted_price' => '1000', 'tax_lines' => []]],
            'discount_codes' => [['code' => 'NOV']],
        ], $extra);
    }

    public function test_shopify_store(): void
    {
        [$company, $user, $token] = $this->makeCompany('Shop SARL');
        $api = fn () => $this->api($token);
        $cement = $api()->postJson('/api/core/products', ['name' => 'Ciment CPJ 45', 'sku' => 'CIM45', 'sale_price' => 5000, 'tax_rate' => 18, 'track_stock' => true])->json('data.id');
        $paint = $api()->postJson('/api/core/products', ['name' => 'Peinture 20 L', 'sku' => 'PNT20', 'sale_price' => 25000, 'tax_rate' => 18, 'track_stock' => true])->json('data.id');
        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Dépôt', 'is_default' => true])->json('data.id');
        $api()->postJson('/api/stock/moves', ['product_id' => $cement, 'warehouse_id' => $wh, 'quantity' => 30, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();
        $api()->postJson('/api/stock/moves', ['product_id' => $paint, 'warehouse_id' => $wh, 'quantity' => 5, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();

        $orders = [];
        Http::fake([
            'maboutique.myshopify.com/admin/api/2024-10/shop.json' => Http::response(['shop' => ['name' => 'Ma Boutique', 'currency' => 'XOF', 'taxes_included' => true, 'primary_location_id' => 55, 'domain' => 'maboutique.ci']]),
            'maboutique.myshopify.com/admin/api/2024-10/locations.json' => Http::response(['locations' => [['id' => 55, 'active' => true]]]),
            'maboutique.myshopify.com/admin/api/2024-10/webhooks.json' => fn ($r) => Http::response($r->method() === 'GET' ? ['webhooks' => []] : ['webhook' => ['id' => $r['webhook']['topic'] === 'orders/create' ? 91 : 92]]),
            'maboutique.myshopify.com/admin/api/2024-10/products.json*' => fn ($r) => Http::response($r->method() === 'GET'
                ? ['products' => [['id' => 700, 'title' => 'Ciment', 'status' => 'active', 'variants' => [['id' => 801, 'sku' => 'CIM45', 'price' => '5900', 'inventory_item_id' => 9801]]]]]
                : ['product' => ['id' => 710, 'variants' => [['id' => 811, 'inventory_item_id' => 9811]]]]),
            'maboutique.myshopify.com/admin/api/2024-10/variants/*' => Http::response(['variant' => []]),
            'maboutique.myshopify.com/admin/api/2024-10/products/*' => Http::response(['product' => []]),
            'maboutique.myshopify.com/admin/api/2024-10/inventory_levels/set.json' => Http::response(['inventory_level' => []]),
            'maboutique.myshopify.com/admin/api/2024-10/orders.json*' => function () use (&$orders) {
                return Http::response(['orders' => $orders]);
            },
            'maboutique.myshopify.com/admin/api/2024-10/graphql.json' => Http::response(['data' => ['orderMarkAsPaid' => ['userErrors' => []]]]),
            'maboutique.myshopify.com/admin/api/2024-10/webhooks/*' => Http::response([]),
        ]);

        $s = $api()->postJson('/api/shopify/stores', ['url' => 'maboutique', 'consumer_key' => 'shpat_x', 'consumer_secret' => 'shpss_secret'])->assertOk()->json('data');
        $this->assertEquals(['https://maboutique.myshopify.com', 'Ma Boutique', 'XOF', true, 'shopify'], [$s['url'], $s['name'], $s['currency'], $s['webhooks'], $s['platform']]);
        $sid = $s['id'];
        $this->assertEmpty($api()->getJson('/api/woocommerce')->assertOk()->json('data')); // séparé des boutiques WooCommerce

        // Ciment déjà sur Shopify : relié par SKU ; peinture : créée
        $api()->postJson("/api/shopify/stores/$sid/link")->assertOk()->assertJsonPath('data.linked', 1);
        $api()->postJson("/api/shopify/stores/$sid/products", ['product_ids' => [$paint], 'publish' => true])->assertOk()->assertJsonPath('push.created', 1);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), 'products.json') && $r['product']['variants'][0]['price'] === '29500' && $r['product']['variants'][0]['sku'] === 'PNT20');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'inventory_levels/set.json') && $r['inventory_item_id'] === 9801 && $r['available'] === 30 && $r['location_id'] === 55);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'inventory_levels/set.json') && $r['inventory_item_id'] === 9811 && $r['available'] === 5);
        $api()->postJson("/api/shopify/stores/$sid/sync", ['what' => 'products'])->assertOk()->assertJsonPath('data.products.updated', 0);

        // Commande Shopify (paiement à la livraison, TTC, remise) → commande client HT
        $orders = [$this->order(5001)];
        $api()->postJson("/api/shopify/stores/$sid/sync", ['what' => 'orders'])->assertOk()->assertJsonPath('data.orders', 1);
        $o = $api()->getJson("/api/shopify/stores/$sid/orders")->assertOk()->json('data.0');
        $this->assertEquals(['1001', 'processing', 'Yao Kouassi', 'https://maboutique.myshopify.com/admin/orders/5001'], [$o['number'], $o['status'], $o['customer'], $o['admin_url']]);
        $doc = DB::table('trade_documents')->find($o['order']['id']);
        $line = DB::table('trade_document_lines')->where('document_id', $doc->id)->where('product_id', $cement)->first();
        $this->assertEquals([14236.44, 18.01], [round((float) $line->quantity * (float) $line->unit_price, 2), round((float) $line->tax_rate, 2)]); // 3×5900 − 900 − TVA incluse
        $this->assertSame(27, app(\App\Modules\Shopify\ShopifySync::class)->available($company, DB::table('woo_stores')->find($sid), [$cement])[$cement]);

        // Webhook signé (clé secrète de l'application) : commande payée en ligne
        $this->flushHeaders();
        $tok = DB::table('woo_stores')->where('id', $sid)->value('token');
        $body = json_encode($this->order(5002, ['order_number' => 1002, 'financial_status' => 'paid', 'payment_gateway_names' => ['Wave'], 'line_items' => [['variant_id' => 811, 'title' => 'Peinture', 'sku' => 'PNT20', 'quantity' => 1, 'price' => '29500', 'tax_lines' => [['price' => '4500']]]], 'shipping_lines' => []]));
        $this->call('POST', "/api/shopify-webhook/$tok", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_HMAC_SHA256' => 'x', 'HTTP_X_SHOPIFY_TOPIC' => 'orders/create'], $body)->assertStatus(401);
        $this->call('POST', "/api/shopify-webhook/$tok", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'shpss_secret', true)), 'HTTP_X_SHOPIFY_TOPIC' => 'orders/create'], $body)->assertOk();
        $o2 = collect($this->api($token)->getJson("/api/shopify/stores/$sid/orders")->json('data'))->firstWhere('number', '1002');
        $this->assertEquals(['processing', 'paid'], [$o2['status'], $o2['invoice']['payment_status']]);
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), 'graphql.json'));

        // Paiement à la livraison encaissé dans l'ERP → marquée payée sur Shopify
        $sync = app(\App\Modules\Shopify\ShopifySync::class);
        app()->instance('tenant', $company);
        $l1 = DB::table('woo_links')->where('store_id', $sid)->where('remote_id', 5001)->first();
        $inv = (new \ReflectionMethod($sync, 'invoiceFrom'))->invoke($sync, \App\Modules\Ventes\Models\SalesDocument::find($l1->local_id));
        $this->api($token)->postJson('/api/ventes/payments', ['document_id' => $inv->id, 'date' => now()->toDateString(), 'amount' => $inv->total, 'method' => 'cash'])->assertSuccessful();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'graphql.json') && $r['variables']['input']['id'] === 'gid://shopify/Order/5001');

        // Annulée sur Shopify (non facturée) → annulée dans l'ERP
        $orders = [$this->order(5003, ['order_number' => 1003])];
        $this->api($token)->postJson("/api/shopify/stores/$sid/sync", ['what' => 'orders'])->assertOk();
        DB::table('woo_stores')->where('id', $sid)->update(['last_orders_sync_at' => now()->subHour()]);
        $orders = [$this->order(5003, ['order_number' => 1003, 'cancelled_at' => now()->toIso8601String()])];
        $this->api($token)->postJson("/api/shopify/stores/$sid/sync", ['what' => 'orders'])->assertOk();
        $this->assertSame('cancelled', DB::table('trade_documents')->where('id', DB::table('woo_links')->where('remote_id', 5003)->value('local_id'))->value('status'));

        $this->api($token)->deleteJson("/api/shopify/stores/$sid")->assertNoContent();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), 'webhooks/91.json'));
    }
}
