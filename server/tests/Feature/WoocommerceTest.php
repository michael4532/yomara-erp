<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Connecteur WooCommerce : connexion, publication des produits (prix TTC, stock disponible), import des commandes, statuts. */
class WoocommerceTest extends TestCase
{
    protected function order(int $id, string $status, array $extra = []): array
    {
        return array_replace([
            'id' => $id, 'number' => (string) $id, 'status' => $status, 'currency' => 'XOF', 'total' => '25000', 'date_created' => now()->subHour()->format('Y-m-d\TH:i:s'),
            'date_paid' => null, 'payment_method' => 'cod', 'payment_method_title' => 'Paiement à la livraison', 'transaction_id' => '', 'customer_note' => 'Appeler avant',
            'billing' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'email' => 'awa@gmail.com', 'phone' => '07 07 12 34 56', 'address_1' => 'Cocody Angré', 'city' => 'Abidjan', 'company' => ''],
            'shipping' => ['address_1' => 'Cocody Angré 8e tranche', 'city' => 'Abidjan'],
            'line_items' => [['product_id' => 501, 'variation_id' => 0, 'name' => 'Ciment CPJ 45', 'sku' => 'CIM45', 'quantity' => 3, 'total' => '18000', 'total_tax' => '0']],
            'shipping_lines' => [['method_title' => 'Livraison Abidjan', 'total' => '2000', 'total_tax' => '0']], 'fee_lines' => [], 'coupon_lines' => [['code' => 'BIENVENUE']],
        ], $extra);
    }

    public function test_store_products_and_orders(): void
    {
        [$company, $user, $token] = $this->makeCompany('Woo SARL');
        $api = fn () => $this->api($token);
        $cement = $api()->postJson('/api/core/products', ['name' => 'Ciment CPJ 45', 'sku' => 'CIM45', 'sale_price' => 5000, 'tax_rate' => 18, 'track_stock' => true])->json('data.id');
        $iron = $api()->postJson('/api/core/products', ['name' => 'Fer à béton 10 mm', 'sku' => 'FER10', 'sale_price' => 4000, 'tax_rate' => 0, 'track_stock' => true])->json('data.id');
        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Dépôt', 'is_default' => true])->json('data.id');
        $api()->postJson('/api/stock/moves', ['product_id' => $cement, 'warehouse_id' => $wh, 'quantity' => 40, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();
        $api()->postJson('/api/stock/moves', ['product_id' => $iron, 'warehouse_id' => $wh, 'quantity' => 12, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();

        $orders = [$this->order(9001, 'processing'), $this->order(9002, 'pending', ['payment_method' => 'bacs'])];
        Http::fake([
            'boutique.test/wp-json/wc/v3/products/batch' => fn ($r) => Http::response(isset($r['create'])
                ? ['create' => collect($r['create'])->map(fn ($p, $i) => $p['sku'] === 'CIM45' ? ['id' => 0, 'error' => ['code' => 'product_invalid_sku', 'message' => 'SKU déjà utilisé', 'data' => ['resource_id' => 501]]] : ['id' => 700 + $i])->all()]
                : ['update' => collect($r['update'])->map(fn ($p) => ['id' => $p['id']])->all()]),
            'boutique.test/wp-json/wc/v3/products/categories*' => Http::response([]),
            'boutique.test/wp-json/wc/v3/products?*' => Http::response([['id' => 501, 'type' => 'simple', 'sku' => 'CIM45', 'name' => 'Ciment']]),
            'boutique.test/wp-json/wc/v3/settings/general/woocommerce_currency' => Http::response(['value' => 'XOF']),
            'boutique.test/wp-json/wc/v3/settings/tax/woocommerce_prices_include_tax' => Http::response(['value' => 'yes']),
            'boutique.test/wp-json/wc/v3/settings/general/woocommerce_calc_taxes' => Http::response(['value' => 'yes']),
            'boutique.test/wp-json/wc/v3/system_status' => Http::response(['environment' => ['version' => '9.4.1', 'site_title' => 'Boutique Woo']]),
            'boutique.test/wp-json/wc/v3/webhooks?*' => Http::response([]),
            'boutique.test/wp-json/wc/v3/webhooks' => fn ($r) => Http::response(['id' => $r['topic'] === 'order.created' ? 11 : 12]),
            'boutique.test/wp-json/wc/v3/orders?*' => function () use (&$orders) {
                return Http::response($orders);
            },
            'boutique.test/wp-json/wc/v3/orders/*' => Http::response(['id' => 9001]),
        ]);

        // Connexion : adresse nettoyée, réglages du site lus, webhooks créés
        $store = $api()->postJson('/api/woocommerce/stores', ['url' => 'boutique.test/wp-admin/', 'consumer_key' => 'ck_1', 'consumer_secret' => 'cs_1'])->assertOk()->json('data');
        $this->assertEquals(['https://boutique.test', 'XOF', true, true, '9.4.1'], [$store['url'], $store['currency'], $store['prices_include_tax'], $store['webhooks'], $store['version']]);
        Http::assertSent(fn ($r) => $r->url() === 'https://boutique.test/wp-json/wc/v3/webhooks' && $r['topic'] === 'order.updated' && str_contains($r['delivery_url'], '/api/woocommerce-webhook/'));
        $sid = $store['id'];

        // Publication : prix TTC, stock disponible ; référence déjà présente sur le site → reliée
        $api()->postJson("/api/woocommerce/stores/$sid/products", ['product_ids' => [$cement, $iron], 'publish' => true])->assertOk()->assertJsonPath('push.created', 1);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), 'products/batch') && isset($r['create']) && collect($r['create'])->contains(fn ($p) => $p['sku'] === 'CIM45' && $p['regular_price'] === '5900' && $p['stock_quantity'] === 40));
        $links = DB::table('woo_links')->where('store_id', $sid)->pluck('remote_id', 'local_id');
        $this->assertEquals([501, 701], [$links[$cement], $links[$iron]]);
        // Rien n'a changé : aucun envoi ; le prix change : mise à jour seule de ce produit
        $api()->postJson("/api/woocommerce/stores/$sid/sync", ['what' => 'products'])->assertOk()->assertJsonPath('data.products.updated', 1); // le ciment relié reçoit son état
        $api()->postJson("/api/woocommerce/stores/$sid/sync", ['what' => 'products'])->assertOk()->assertJsonPath('data.products.updated', 0);
        $api()->putJson("/api/core/products/$iron", ['sale_price' => 4500])->assertOk();
        $api()->postJson("/api/woocommerce/stores/$sid/sync", ['what' => 'products'])->assertOk()->assertJsonPath('data.products.updated', 1);
        Http::assertSent(fn ($r) => isset($r['update']) && collect($r['update'])->contains(fn ($p) => $p['id'] === 701 && $p['regular_price'] === '4500'));

        // Relevé des commandes : 9001 (en cours, à la livraison) importée, 9002 (en attente de paiement) ignorée
        $api()->postJson("/api/woocommerce/stores/$sid/sync", ['what' => 'orders'])->assertOk()->assertJsonPath('data.orders', 1);
        $list = $api()->getJson("/api/woocommerce/stores/$sid/orders")->assertOk()->json('data');
        $this->assertCount(1, $list);
        $o = $list[0];
        $this->assertEquals(['9001', 'processing', 'Awa Koné', null], [$o['number'], $o['status'], $o['customer'], $o['invoice']]);
        $doc = DB::table('trade_documents')->find($o['order']['id']);
        $this->assertEquals(['order', 'WOO-9001', 20000.0], [$doc->type, $doc->reference, (float) $doc->total]);
        $this->assertStringContainsString('BIENVENUE', $doc->notes);
        $this->assertSame($cement, DB::table('trade_document_lines')->where('document_id', $doc->id)->where('description', 'Ciment CPJ 45')->value('product_id'));
        // Stock publié : 40 − 3 réservés par la commande non facturée
        $this->assertSame(37, app(\App\Modules\Woocommerce\WooSync::class)->available($company, DB::table('woo_stores')->find($sid), [$cement])[$cement]);

        // Webhook signé : la commande 9002 est payée par virement (en attente) → importée ; mauvaise signature refusée
        $this->flushHeaders();
        $tok = DB::table('woo_stores')->where('id', $sid)->value('token');
        $secret = DB::table('woo_stores')->where('id', $sid)->value('webhook_secret');
        $body = json_encode($this->order(9002, 'on-hold', ['payment_method' => 'bacs', 'payment_method_title' => 'Virement bancaire', 'total' => '9000',
            'line_items' => [['product_id' => 701, 'variation_id' => 0, 'name' => 'Fer à béton 10 mm', 'sku' => 'FER10', 'quantity' => 2, 'total' => '9000', 'total_tax' => '0']], 'shipping_lines' => []]));
        $this->call('POST', "/api/woocommerce-webhook/$tok", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WC_WEBHOOK_SIGNATURE' => 'faux', 'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created'], $body)->assertStatus(401);
        $this->call('POST', "/api/woocommerce-webhook/$tok", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
            'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created'], $body)->assertOk();
        $this->call('POST', "/api/woocommerce-webhook/$tok", ['webhook_id' => '11'])->assertOk(); // ping
        $l2 = DB::table('woo_links')->where('store_id', $sid)->where('entity', 'order')->where('remote_id', 9002)->first();
        $this->assertNotNull($l2);
        $this->assertSame(1, DB::table('partners')->where('company_id', $company->id)->where('email', 'awa@gmail.com')->count()); // client reconnu

        // Virement reçu dans l'ERP : facture + encaissement → la commande passe « En cours » sur le site
        $api = fn () => $this->api($token);
        $sync = app(\App\Modules\Woocommerce\WooSync::class);
        app()->instance('tenant', $company);
        $invoice = (new \ReflectionMethod($sync, 'invoiceFrom'))->invoke($sync, \App\Modules\Ventes\Models\SalesDocument::find($l2->local_id));
        $api()->postJson('/api/ventes/payments', ['document_id' => $invoice->id, 'date' => now()->toDateString(), 'amount' => $invoice->total, 'method' => 'bank_transfer'])->assertSuccessful();
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), 'orders/9002') && $r['status'] === 'processing');

        // Commande 9001 terminée sur le site (payée à la livraison) → facture et encaissement créés, sans renvoi au site
        $this->flushHeaders();
        $body = json_encode($this->order(9001, 'completed', ['date_completed' => now()->format('Y-m-d\TH:i:s')]));
        $this->call('POST', "/api/woocommerce-webhook/$tok", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WC_WEBHOOK_SIGNATURE' => base64_encode(hash_hmac('sha256', $body, $secret, true)),
            'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.updated'], $body)->assertOk();
        $o = collect($this->api($token)->getJson("/api/woocommerce/stores/$sid/orders")->json('data'))->firstWhere('number', '9001');
        $this->assertEquals(['completed', 'paid'], [$o['status'], $o['invoice']['payment_status']]);
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), 'orders/9001'));
        // stock : la facture a fait sortir les 3 sacs ; plus de réservation
        $this->assertSame(37, $sync->available($company, DB::table('woo_stores')->find($sid), [$cement])[$cement]);

        // Annulation sur le site d'une commande non facturée → annulée dans l'ERP
        $this->flushHeaders();
        $orders = [$this->order(9003, 'processing')];
        $this->api($token)->postJson("/api/woocommerce/stores/$sid/sync", ['what' => 'orders'])->assertOk();
        $orders = [$this->order(9003, 'cancelled')];
        DB::table('woo_stores')->where('id', $sid)->update(['last_orders_sync_at' => now()->subHour()]);
        $this->api($token)->postJson("/api/woocommerce/stores/$sid/sync", ['what' => 'orders'])->assertOk();
        $l3 = DB::table('woo_links')->where('remote_id', 9003)->first();
        $this->assertSame('cancelled', DB::table('trade_documents')->where('id', $l3->local_id)->value('status'));

        $this->assertNotEmpty($this->api($token)->getJson("/api/woocommerce/stores/$sid/logs")->assertOk()->json('data'));
        $p = collect($this->api($token)->getJson("/api/woocommerce/stores/$sid/products?filter=published")->assertOk()->json('data'))->firstWhere('id', $cement);
        $this->assertEquals([true, true, 5900.0], [$p['published'], $p['online'], (float) $p['price']]);

        // Déconnexion : webhooks supprimés du site
        $this->api($token)->deleteJson("/api/woocommerce/stores/$sid")->assertNoContent();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'webhooks/11'));
        $this->assertSame(0, DB::table('woo_links')->where('store_id', $sid)->count());
    }

    public function test_one_click_connection(): void
    {
        [$company, $user, $token] = $this->makeCompany('Woo Clic');
        Http::fake([
            'shop.test/wp-json/wc/v3/webhooks?*' => Http::response([]),
            'shop.test/wp-json/wc/v3/webhooks' => Http::response(['id' => 5]),
            'shop.test/wp-json/wc/v3/*' => Http::response([]),
        ]);
        $r = $this->api($token)->postJson('/api/woocommerce/authorize', ['url' => 'https://shop.test/', 'import_days' => 0])->assertOk()->json();
        $this->assertStringStartsWith('https://shop.test/wc-auth/v1/authorize?', $r['url']);
        $this->assertStringContainsString('user_id='.$r['state'], $r['url']);
        $this->api($token)->getJson('/api/woocommerce/authorize/'.$r['state'])->assertOk()->assertJsonPath('status', 'pending');
        $this->api($token)->postJson('/api/woocommerce/authorize', ['url' => 'http://127.0.0.1'])->assertStatus(422);

        // WordPress renvoie les clés : mauvais état refusé, bon état → boutique créée
        $this->flushHeaders();
        $keys = ['key_id' => 3, 'consumer_key' => 'ck_'.str_repeat('a', 40), 'consumer_secret' => 'cs_'.str_repeat('b', 40), 'key_permissions' => 'read_write'];
        $this->postJson('/api/woocommerce-auth/'.$r['state'], ['user_id' => 'autre'] + $keys)->assertStatus(400);
        $this->postJson('/api/woocommerce-auth/'.str_repeat('x', 40), ['user_id' => str_repeat('x', 40)] + $keys)->assertStatus(400);
        $this->postJson('/api/woocommerce-auth/'.$r['state'], ['user_id' => $r['state']] + $keys)->assertOk();
        $st = $this->api($token)->getJson('/api/woocommerce/authorize/'.$r['state'])->assertOk()->assertJsonPath('status', 'connected')->json('store');
        $this->assertSame('https://shop.test', $st['url']);
        $this->assertSame(1, DB::table('woo_stores')->where('company_id', $company->id)->count());
        // rejouer la même réponse ne crée rien de plus
        $this->flushHeaders();
        $this->postJson('/api/woocommerce-auth/'.$r['state'], ['user_id' => $r['state']] + $keys)->assertStatus(400);
        // une autre société ne voit pas l'état
        [, , $other] = $this->makeCompany('Autre');
        $this->api($other)->getJson('/api/woocommerce/authorize/'.$r['state'])->assertNotFound();
        $this->get('/woocommerce/connecte?success=1')->assertOk()->assertSee('Boutique autorisée');
    }
}
