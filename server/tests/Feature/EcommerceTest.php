<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Logistique\Models\Delivery;
use App\Modules\Sites\Models\SiteOrder;
use App\Modules\Sites\SiteService;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Boutique en ligne : réservation du stock, commandes WhatsApp, préparation, expédition (facture + livraison), encaissements, flux, statistiques. */
class EcommerceTest extends TestCase
{
    private function public(): void
    {
        $this->flushHeaders();
        app()->forgetInstance('tenant');
    }

    public function test_order_lifecycle_reservation_shipping_delivery_payments_feeds_and_stats(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Awa Mode');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $this->assertSame('applied', $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0.status'));
        app()->instance('tenant', $company);
        $site = app(SiteService::class)->create($company, 'boutique-eclat', 'Awa Mode');
        app(SiteService::class)->publish($site, true);
        $robe = $this->uuid();
        $push('products', ['name' => 'Robe wax Akwaba', 'sale_price' => 15000, 'tax_rate' => 18, 'type' => 'product', 'track_stock' => true, 'sku' => 'RB-01', 'image' => 'https://cdn.exemple.ci/robe.jpg'], $robe);
        $foulard = $this->uuid();
        $push('products', ['name' => 'Foulard soie', 'sale_price' => 5000, 'tax_rate' => 0, 'type' => 'product', 'track_stock' => false], $foulard);
        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Boutique', 'is_default' => true])->json('data.id');
        $push('stock_moves', ['product_id' => $robe, 'warehouse_id' => $wh, 'quantity' => 5, 'type' => 'adjustment', 'date' => now()->toDateString()]);
        $api()->putJson('/api/sites/shop/settings', ['enabled' => true, 'pickup' => true, 'zones' => [['name' => 'Abidjan Sud', 'fee' => 1000]],
            'payment_online' => false, 'payment_cod' => true, 'payment_momo' => true, 'momo_instructions' => 'Orange Money 07 07 55 44 33'])->assertOk();
        $driver = User::create(['company_id' => $company->id, 'name' => 'Ibrahim Coulibaly', 'email' => 'ibrahim@awa.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Livreur')->value('id')]);

        // Commande du site, paiement à la livraison : 2 robes réservées
        $cart = fn (array $items) => json_encode(array_map(fn ($id, $q) => ['id' => $id, 'quantity' => $q], array_keys($items), $items));
        $base = ['name' => 'Fatou Diallo', 'phone' => '05 44 33 22 11', 'delivery' => 'Abidjan Sud', 'address' => 'Koumassi, rue 12', 'payment' => 'cod'];
        $this->public();
        $this->post('/site/awa-mode/commande', $base + ['cart' => $cart([$robe => 2, $foulard => 1])])->assertRedirectContains('/commande/');
        $o1 = SiteOrder::withoutGlobalScopes()->orderByDesc('id')->first();
        $this->assertEquals(['site', 'order', 41400.0], [$o1->channel, SalesDocument::withoutGlobalScopes()->find($o1->document_id)->type, $o1->total]);
        $this->get('/site/awa-mode/boutique')->assertSee('Plus que 3');
        $this->post('/site/awa-mode/commande', $base + ['cart' => $cart([$robe => 4])])->assertRedirectContains('plus+que+3+en+stock');

        // Commande reçue sur WhatsApp, saisie par l'équipe : retrait en boutique, Mobile Money, confirmée d'office
        $w = $api()->postJson('/api/ecommerce/orders', ['channel' => 'whatsapp', 'name' => 'Koffi Yao', 'phone' => '0102030405', 'delivery' => 'pickup', 'payment' => 'momo',
            'items' => [['id' => $robe, 'quantity' => 1]], 'confirm' => true])->assertCreated()->json('data');
        $this->assertEquals(['whatsapp', 'WhatsApp', 'confirmed', true, 17700.0], [$w['channel'], $w['channel_label'], $w['status'], $w['pickup'], (float) $w['to_collect']]);
        app()->instance('tenant', $company);
        $this->assertEquals([$robe => 3.0], app(\App\Modules\Ecommerce\EcommerceService::class)->reserved($company, [$robe]));
        $api()->postJson('/api/ecommerce/orders', ['channel' => 'facebook', 'name' => 'X', 'phone' => '01', 'delivery' => 'pickup', 'payment' => 'cod', 'items' => [['id' => $robe, 'quantity' => 3]]])
            ->assertStatus(422)->assertJsonFragment(['message' => '« Robe wax Akwaba » : plus que 2 en stock.']);
        // Boutique fermée : les commandes saisies restent possibles, le site non
        $api()->putJson('/api/sites/shop/settings', ['enabled' => false])->assertOk();
        $f = $api()->postJson('/api/ecommerce/orders', ['channel' => 'facebook', 'name' => 'Aya Kouamé', 'phone' => '0708091011', 'delivery' => 'Abidjan Sud', 'address' => 'Marcory Zone 4',
            'payment' => 'cod', 'items' => [['id' => $foulard, 'quantity' => 2]]])->assertCreated()->json('data');
        $api()->putJson('/api/sites/shop/settings', ['enabled' => true])->assertOk();

        // Vente au comptoir entre-temps : il ne reste qu'une robe physique pour 3 promises → rupture signalée sur la commande la plus récente
        $push('stock_moves', ['product_id' => $robe, 'warehouse_id' => $wh, 'quantity' => -3, 'type' => 'adjustment', 'date' => now()->toDateString()]);
        $rows = collect($api()->getJson('/api/ecommerce/orders')->assertOk()->json('data'))->keyBy('id');
        $this->assertCount(3, $rows);
        $this->assertSame([], $rows[$o1->id]['shortages']);
        $this->assertEquals([['name' => 'Robe wax Akwaba', 'needed' => 1, 'available' => 0]], $rows[$w['id']]['shortages']);
        $push('stock_moves', ['product_id' => $robe, 'warehouse_id' => $wh, 'quantity' => 3, 'type' => 'adjustment', 'date' => now()->toDateString()]);
        $ov = $api()->getJson('/api/ecommerce/overview')->assertOk()->json('data');
        $this->assertEquals(['new' => 2, 'confirmed' => 1], $ov['counts']);
        $this->assertContains(['id' => $driver->id, 'name' => 'Ibrahim Coulibaly'], $ov['drivers']);

        // Bon de préparation : PDF, commandes passées « en préparation »
        $pdf = $api()->get('/api/ecommerce/picking?ids='.$o1->id.','.$w['id'].'&mark=1')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertSame(['preparing', 'preparing'], [$o1->fresh()->status, SiteOrder::withoutGlobalScopes()->find($w['id'])->status]);

        // Expédition : facture (sortie de stock), bon de livraison avec montant à encaisser, livreur
        $s = $api()->postJson("/api/ecommerce/orders/{$o1->id}/ship", ['driver_id' => $driver->id])->assertOk()->json('data');
        $this->assertSame('shipped', $s['status']);
        $inv = SalesDocument::withoutGlobalScopes()->find($s['invoice']['id']);
        $this->assertEquals(['invoice', 'validated', $o1->document_id, 41400.0], [$inv->type, $inv->status, $inv->source_id, (float) $inv->total]);
        $this->assertEquals(-2.0, (float) DB::table('stock_moves')->where('product_id', $robe)->where('quantity', '<', 0)->where('quantity', '>', -3)->sum('quantity'));
        $del = Delivery::withoutGlobalScopes()->find($s['delivery']['id']);
        $this->assertEquals([$inv->id, 41400.0, 1000.0, $driver->id, 'planned', 'Fatou Diallo'], [$del->source_document_id, (float) $del->cod_amount, (float) $del->fee, $del->driver_id, $del->status, $del->recipient_name]);
        $this->assertEquals([$robe => 1.0], app(\App\Modules\Ecommerce\EcommerceService::class)->reserved($company, [$robe])); // la facture a fait sortir le stock
        $api()->postJson("/api/ecommerce/orders/{$o1->id}/ship")->assertStatus(422);
        $api()->postJson("/api/ecommerce/orders/{$o1->id}/cancel")->assertStatus(422)->assertJsonFragment(['message' => 'Commande déjà facturée ('.$inv->number.') : faites un avoir dans les Ventes, puis annulez la commande.']);

        // Le livreur livre et encaisse (Logistique) : facture réglée, commande livrée et payée
        $dtoken = $driver->createToken('t')->plainTextToken;
        $this->api($dtoken)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'lg_deliveries', 'op' => 'upsert', 'record_id' => $del->id,
            'data' => ['status' => 'delivered', 'cod_collected' => 41400, 'cod_method' => 'mobile_money', 'signed_by' => 'Fatou']]]])->assertOk();
        $o1->refresh();
        $this->assertEquals(['delivered', 'paid'], [$o1->status, $o1->payment_status]);
        $this->assertSame('paid', $inv->fresh()->payment_status);

        // Mobile Money reçu pour la commande WhatsApp : facture + règlement, puis retrait en boutique
        $p = $api()->postJson("/api/ecommerce/orders/{$w['id']}/pay", ['method' => 'mobile_money', 'reference' => 'OM-778899'])->assertOk()->json('data');
        $this->assertEquals(['paid', 'OM-778899', 0.0], [$p['payment_status'], $p['payment_ref'], (float) $p['to_collect']]);
        $this->assertSame('mobile_money', DB::table('payments')->where('document_id', $p['invoice']['id'])->value('method'));
        $api()->postJson("/api/ecommerce/orders/{$w['id']}/pay")->assertStatus(422);
        $d = $api()->postJson("/api/ecommerce/orders/{$w['id']}/deliver")->assertOk()->json('data');
        $this->assertEquals(['delivered', null], [$d['status'], $d['delivery']]);

        // Retrait payé en espèces au comptoir, en passant par l'écran du site vitrine
        $api()->postJson("/api/sites/orders/{$f['id']}/status", ['status' => 'delivered', 'paid' => true])->assertOk()->assertJsonPath('data.payment_status', 'paid');
        $fo = SiteOrder::withoutGlobalScopes()->find($f['id']);
        $this->assertEquals(['delivered', 'cash'], [$fo->status, $fo->payment_method]);
        $this->assertSame('paid', SalesDocument::withoutGlobalScopes()->find($fo->invoice_id)->payment_status);

        // Annulation d'une commande non facturée : document de vente annulé, stock libéré
        $this->public();
        $this->post('/site/awa-mode/commande', $base + ['cart' => $cart([$robe => 1])])->assertRedirectContains('/commande/');
        $o4 = SiteOrder::withoutGlobalScopes()->orderByDesc('id')->first();
        $api()->postJson("/api/ecommerce/orders/{$o4->id}/cancel", ['reason' => 'Client injoignable'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('cancelled', SalesDocument::withoutGlobalScopes()->find($o4->document_id)->status);
        app()->instance('tenant', $company);
        $this->assertSame([], app(\App\Modules\Ecommerce\EcommerceService::class)->reserved($company, [$robe]));
        $this->assertStringContainsString('Client injoignable', $o4->fresh()->staff_note);

        // Flux catalogue : Meta (CSV) et Google (XML), adresse secrète renouvelable
        $api()->putJson('/api/ecommerce/settings', ['brand' => 'Awa Mode Abidjan', 'whatsapp_number' => '07 07 55 44 33'])->assertOk()
            ->assertJsonPath('data.whatsapp_link', 'https://wa.me/2250707554433?text='.rawurlencode('Bonjour Awa Mode, je souhaite passer une commande.'));
        $feeds = $api()->getJson('/api/ecommerce/overview')->json('data.feeds');
        $this->public();
        $csv = $this->get(parse_url($feeds['meta'], PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertSame('id', $lines[0][0]);
        $row = collect($lines)->first(fn ($l) => $l[0] === $robe);
        $this->assertEquals(['Robe wax Akwaba', 'in stock', '17700 XOF', 'https://cdn.exemple.ci/robe.jpg', 'Awa Mode Abidjan', '2'], [$row[1], $row[3], $row[5], $row[8], $row[9], $row[12]]);
        $this->assertStringContainsString('/produit/'.$robe.'/robe-wax-akwaba', $row[7]);
        $xml = $this->get(parse_url($feeds['google'], PHP_URL_PATH))->assertOk()->getContent();
        $this->assertStringContainsString('<g:id>'.$foulard.'</g:id><title>Foulard soie</title>', $xml);
        $this->assertStringContainsString('<g:price>5000 XOF</g:price>', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
        $api()->postJson('/api/ecommerce/settings/feed-token')->assertOk();
        $this->public();
        $this->get(parse_url($feeds['meta'], PHP_URL_PATH))->assertNotFound();

        // Statistiques
        $st = $api()->getJson('/api/ecommerce/stats')->assertOk()->json('data');
        $this->assertEquals([3, 41400 + 17700 + 11000, round((41400 + 17700 + 11000) / 3), 1, 25.0], [$st['orders'], $st['revenue'], $st['average_basket'], $st['cancelled'], $st['cancel_rate']]);
        $this->assertEquals(['site', 'whatsapp', 'facebook'], array_column($st['by_channel'], 'key'));
        $this->assertSame('Robe wax Akwaba', $st['top_products'][0]['name']);
        $this->assertSame(3, $st['delivered']);
        $this->assertNotNull($st['prep_hours']);

        // Droits : lecture seule sans traitement
        $role = $api()->postJson('/api/roles', ['name' => 'Lecture boutique', 'permissions' => ['ecommerce.view']])->assertCreated()->json('data.id');
        $api()->postJson('/api/users', ['name' => 'Lecteur', 'email' => 'lecteur@awa.ci', 'password' => 'lecture123', 'role_id' => $role])->assertCreated();
        $rt = User::where('email', 'lecteur@awa.ci')->first()->createToken('t')->plainTextToken;
        $this->api($rt)->getJson('/api/ecommerce/orders?status=all')->assertOk()->assertJsonCount(4, 'data');
        $this->api($rt)->postJson("/api/ecommerce/orders/{$o4->id}/confirm")->assertForbidden();
        $this->api($rt)->putJson('/api/ecommerce/settings', ['reserve_stock' => false])->assertForbidden();
    }
}
