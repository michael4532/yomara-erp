<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Modules\Core\Models\Product;
use App\Modules\Lots\Models\Lot;
use App\Modules\Stock\Models\StockMove;
use Tests\TestCase;

class ArticlesAvancesTest extends TestCase
{
    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Supermarché Kouamé');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Magasin', 'is_default' => true])->json('data.id');

        return compact('company', 'admin', 'api', 'push', 'wh');
    }

    private function ok(array $r): array
    {
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $r['record'];
    }

    public function test_lots_serials_fefo_expiry_trace_scrap_alerts_receipts_transfer_inventory(): void
    {
        ['company' => $company, 'api' => $api, 'push' => $push, 'wh' => $wh] = $this->env();
        $prod = fn (array $d) => $api()->postJson('/api/core/products', $d + ['type' => 'product', 'track_stock' => true, 'tax_rate' => 0])->assertCreated()->json('data.id');
        $yaourt = $prod(['name' => 'Yaourt nature 1 L', 'sale_price' => 1500, 'purchase_price' => 900, 'tracking' => 'lot', 'use_expiry' => true, 'shelf_life_days' => 20, 'expiry_alert_days' => 5]);
        $phone = $prod(['name' => 'Smartphone Tecno Spark', 'sale_price' => 85000, 'purchase_price' => 60000, 'tracking' => 'serial']);
        $supplier = $api()->postJson('/api/core/partners', ['name' => 'Laiterie du Sud', 'type' => 'supplier'])->json('data.id');
        $client = $api()->postJson('/api/core/partners', ['name' => 'Maquis Le Jardin', 'type' => 'customer'])->json('data.id');
        $d = fn (int $days) => now()->addDays($days)->toDateString();
        $stockOf = fn (string $lotNumber) => (float) StockMove::where('lot_id', Lot::where('number', $lotNumber)->value('id'))->sum('quantity');

        // Entrée par facture fournisseur : lots et dates de péremption saisis
        $this->ok($push('purchase_documents', ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $yaourt, 'description' => 'Yaourt', 'quantity' => 150, 'unit_price' => 900, 'tax_rate' => 0,
                'lots' => [['number' => 'L1', 'expiry_date' => $d(12), 'quantity' => 100], ['number' => 'L2', 'expiry_date' => $d(3), 'quantity' => 50]]],
        ]]));
        $this->assertEquals([100, 50], [$stockOf('L1'), $stockOf('L2')]);

        // Numéros de série obligatoires à l'entrée, uniques en stock
        $r = $push('purchase_documents', ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $phone, 'description' => 'Tecno', 'quantity' => 3, 'unit_price' => 60000, 'tax_rate' => 0, 'lots' => [['number' => 'SN-001']]]]]);
        $this->assertSame('rejected', $r['status']);
        $this->assertStringContainsString('Indiquez les numéros de série de « Smartphone Tecno Spark » (1 sur 3)', $r['message']);
        $this->ok($push('purchase_documents', ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $phone, 'description' => 'Tecno', 'quantity' => 3, 'unit_price' => 60000, 'tax_rate' => 0, 'lots' => [['number' => 'SN-001'], ['number' => 'SN-002'], ['number' => 'SN-003']]]]]));
        $r = $push('purchase_documents', ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $phone, 'description' => 'Tecno', 'quantity' => 1, 'unit_price' => 60000, 'tax_rate' => 0, 'lots' => [['number' => 'SN-002']]]]]);
        $this->assertStringContainsString('SN-002 de « Smartphone Tecno Spark » est déjà en stock', $r['message']);

        // Stock initial saisi à la main avec un lot déjà périmé
        $this->ok($push('stock_moves', ['product_id' => $yaourt, 'warehouse_id' => $wh, 'quantity' => 20, 'type' => 'initial', 'date' => now()->toDateString(), 'lot_number' => 'L0', 'expiry_date' => $d(-2)]));
        $this->assertSame($d(-2), Lot::where('number', 'L0')->first()->expiry_date->toDateString());

        // Vente sans lot indiqué : le lot qui périme le plus tôt d'abord (FEFO), le lot périmé écarté
        $inv = $this->ok($push('sales_documents', ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $yaourt, 'description' => 'Yaourt', 'quantity' => 60, 'unit_price' => 1500, 'tax_rate' => 0]]]));
        $this->assertEquals([90, 0, 20], [$stockOf('L1'), $stockOf('L2'), $stockOf('L0')]);
        // Lot périmé indiqué explicitement : refusé
        $r = $push('sales_documents', ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $yaourt, 'description' => 'Yaourt', 'quantity' => 2, 'unit_price' => 1500, 'tax_rate' => 0, 'lots' => [['lot_id' => Lot::where('number', 'L0')->value('id'), 'quantity' => 2]]]]]);
        $this->assertStringContainsString('Le lot L0 de « Yaourt nature 1 L » est périmé', $r['message']);
        // Numéro de série vendu
        $this->ok($push('sales_documents', ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $phone, 'description' => 'Tecno', 'quantity' => 1, 'unit_price' => 85000, 'tax_rate' => 0, 'lots' => [['number' => 'SN-002']]]]]));
        $this->assertEquals([1, 0, 1], [$stockOf('SN-001'), $stockOf('SN-002'), $stockOf('SN-003')]);

        // Annulation : chaque lot retrouve sa quantité
        $api()->postJson("/api/ventes/documents/{$inv['id']}/cancel")->assertOk();
        $this->assertEquals([100, 50], [$stockOf('L1'), $stockOf('L2')]);

        // Stock par lot et statut de péremption
        $levels = collect($api()->getJson('/api/lots/levels?product_id='.$yaourt)->assertOk()->json('data'))->keyBy('number');
        $this->assertSame('expired', $levels['L0']['status']);
        $this->assertSame('expiring', $levels['L2']['status']);
        $this->assertSame('ok', $levels['L1']['status']);
        $this->assertSame(12, $levels['L1']['days_left']);
        $this->assertCount(1, $api()->getJson('/api/lots/levels?status=expired')->json('data'));
        $dash = collect($api()->getJson('/api/dashboard')->json('data'))->keyBy('key');
        $this->assertSame(1, $dash['lots_expired']['value']);

        // Traçabilité (rappel de lot) et recherche d'un numéro de série
        $trace = $api()->getJson('/api/lots/lots/'.Lot::where('number', 'L2')->value('id').'/trace')->assertOk()->json('data');
        $this->assertEquals(50, $trace['stock']);
        $this->assertEqualsCanonicalizing(['Laiterie du Sud', 'Maquis Le Jardin'], array_column($trace['partners'], 'name'));
        $this->assertCount(3, $trace['moves']); // entrée, vente, annulation
        $found = $api()->getJson('/api/lots/search?q=SN-00')->assertOk()->json('data');
        $this->assertCount(3, $found);
        $this->assertEquals(0, collect($found)->firstWhere('number', 'SN-002')['stock']);

        // Retour client sur un lot précis
        $this->ok($push('sales_documents', ['type' => 'credit_note', 'partner_id' => $client, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $phone, 'description' => 'Tecno', 'quantity' => 1, 'unit_price' => 85000, 'tax_rate' => 0, 'lots' => [['number' => 'SN-002']]]]]));
        $this->assertEquals(1, $stockOf('SN-002'));

        // Alerte de péremption unique ; mise au rebut des périmés
        $this->artisan('erp:lots-expiry')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('company_id', $company->id)->where('module', 'lots')->count());
        $this->assertStringContainsString('lot L0 : périmé depuis 2 j', AppNotification::where('module', 'lots')->value('body'));
        $this->artisan('erp:lots-expiry')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('module', 'lots')->count());
        $api()->postJson('/api/lots/scrap')->assertOk()->assertJsonPath('data.moves', 1);
        $this->assertEquals(0, $stockOf('L0'));
        $this->assertEquals(-20, (float) StockMove::where('lot_id', Lot::where('number', 'L0')->value('id'))->where('type', 'loss')->sum('quantity'));

        // Transfert d'un lot ; inventaire par lot
        $wh2 = $api()->postJson('/api/stock/warehouses', ['name' => 'Dépôt'])->json('data.id');
        $l1 = Lot::where('number', 'L1')->value('id');
        $api()->postJson('/api/stock/transfer', ['product_id' => $yaourt, 'from_warehouse_id' => $wh, 'to_warehouse_id' => $wh2, 'quantity' => 30, 'lot_id' => $l1])->assertCreated();
        $this->assertEquals(30, (float) StockMove::where('lot_id', $l1)->where('warehouse_id', $wh2)->sum('quantity'));
        $api()->postJson('/api/stock/inventory', ['warehouse_id' => $wh, 'counts' => [['product_id' => $yaourt, 'lot_id' => $l1, 'quantity' => 65]]])->assertOk();
        $this->assertEquals(65, (float) StockMove::where('lot_id', $l1)->where('warehouse_id', $wh)->sum('quantity'));

        // Réception d'un bon de commande : lot créé automatiquement si non saisi (péremption = durée de conservation)
        $po = $this->ok($push('purchase_documents', ['type' => 'purchase_order', 'partner_id' => $supplier, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $yaourt, 'description' => 'Yaourt', 'quantity' => 40, 'unit_price' => 900, 'tax_rate' => 0]]]));
        $line = \App\Modules\Core\Models\TradeDocumentLine::where('document_id', $po['id'])->value('id');
        $this->ok($push('purchase_receipts', ['order_id' => $po['id'], 'date' => now()->toDateString(), 'status' => 'done', 'lines' => [['order_line_id' => $line, 'quantity' => 40]]]));
        $auto = Lot::where('product_id', $yaourt)->where('number', 'like', 'L'.now()->format('ymd').'-%')->first();
        $this->assertNotNull($auto);
        $this->assertSame($d(20), $auto->expiry_date->toDateString());

        // Lots synchronisés ; réglage « lots périmés autorisés »
        $this->assertGreaterThanOrEqual(7, count($api()->getJson('/api/sync/pull?cursor=0')->json('changes.stock_lots.upserts')));
        $api()->putJson('/api/lots/settings', ['block_expired' => false, 'alert_days' => 10])->assertOk()->assertJsonPath('data.alert_days', 10);
        $this->ok($push('stock_moves', ['product_id' => $yaourt, 'warehouse_id' => $wh, 'quantity' => 5, 'type' => 'initial', 'date' => now()->toDateString(), 'lot_number' => 'L9', 'expiry_date' => $d(-1)]));
        $this->ok($push('sales_documents', ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $yaourt, 'description' => 'Yaourt', 'quantity' => 1, 'unit_price' => 1500, 'tax_rate' => 0, 'lots' => [['number' => 'L9', 'quantity' => 1]]]]]));
        $this->assertEquals(4, $stockOf('L9'));

        // Un service ne se suit pas par série ; lot sans mouvement supprimable seulement
        $api()->postJson('/api/core/products', ['name' => 'Installation', 'type' => 'service', 'tracking' => 'serial'])->assertStatus(422);

        // Rapport de stock par lot
        $rep = $api()->postJson('/api/rapports/preview', ['source' => 'stock', 'config' => ['dimensions' => ['lot'], 'measures' => ['quantity'], 'period' => 'all',
            'filters' => [['field' => 'product', 'op' => 'eq', 'value' => $yaourt]]]])->assertOk()->json('data.rows');
        $this->assertEquals(95, collect($rep)->firstWhere('labels.lot', 'L1')['values']['quantity']); // 65 au magasin + 30 au dépôt
    }

    public function test_price_lists_quantity_breaks_packagings_and_stock_in_units(): void
    {
        ['api' => $api, 'push' => $push, 'wh' => $wh] = $this->env();
        $cat = $this->ok($push('product_categories', ['name' => 'Épicerie']))['id'] ?? null;
        $cat ??= \App\Modules\Core\Models\ProductCategory::where('name', 'Épicerie')->value('id');
        $other = \App\Modules\Core\Models\ProductCategory::create(['name' => 'Boissons'])->id;
        $prod = fn (array $d) => $api()->postJson('/api/core/products', $d + ['type' => 'product', 'track_stock' => true, 'tax_rate' => 0]);
        $riz = $prod(['name' => 'Riz 25 kg', 'sale_price' => 20000, 'category_id' => $cat])->json('data.id');
        $eau = $prod(['name' => 'Eau 1,5 L', 'sale_price' => 400, 'category_id' => $other])->json('data.id');

        // Conditionnements : facteur > 1, codes-barres uniques
        $prod(['name' => 'Huile X', 'sale_price' => 1000, 'packagings' => [['name' => 'Carton', 'factor' => 1]]])->assertStatus(422);
        $huile = $prod(['name' => 'Huile 1 L', 'sale_price' => 3000, 'purchase_price' => 2400, 'category_id' => $cat, 'barcode' => '6001',
            'packagings' => [['name' => 'Carton de 12', 'factor' => 12, 'barcode' => 'C6001', 'sale_price' => 33000], ['name' => 'Pack de 6', 'factor' => '6']]])->assertCreated()->json('data');
        $this->assertSame([['name' => 'Carton de 12', 'factor' => 12, 'barcode' => 'C6001', 'sale_price' => 33000], ['name' => 'Pack de 6', 'factor' => 6]], $huile['packagings']);
        $prod(['name' => 'Huile Y', 'sale_price' => 1000, 'barcode' => 'C6001'])->assertStatus(422);
        $prod(['name' => 'Huile Z', 'sale_price' => 1000, 'packagings' => [['name' => 'Carton', 'factor' => 10, 'barcode' => '6001']]])->assertStatus(422);

        // Listes de prix
        $this->assertSame('rejected', $push('pl_lists', ['name' => 'X', 'items' => [['scope' => 'category', 'category_id' => $cat, 'mode' => 'price', 'price' => 100]]])['status']);
        $gros = $this->uuid();
        $this->ok($push('pl_lists', ['name' => 'Grossistes', 'items' => [
            ['scope' => 'product', 'product_id' => $riz, 'mode' => 'price', 'price' => 18000],
            ['scope' => 'product', 'product_id' => $riz, 'mode' => 'price', 'price' => 17000, 'min_qty' => 50],
            ['scope' => 'category', 'category_id' => $cat, 'mode' => 'discount', 'discount' => 5],
            ['scope' => 'all', 'mode' => 'discount', 'discount' => 2],
        ]], $gros));
        $promo = $this->uuid();
        $this->ok($push('pl_lists', ['name' => 'Promo fin d\'année', 'is_default' => true, 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDays(10)->toDateString(),
            'items' => [['scope' => 'all', 'mode' => 'discount', 'discount' => 3]]], $promo));
        $grossiste = $api()->postJson('/api/core/partners', ['name' => 'Grossiste Adjamé', 'type' => 'customer', 'price_list_id' => $gros])->json('data.id');
        $particulier = $api()->postJson('/api/core/partners', ['name' => 'Mme Koné', 'type' => 'customer'])->json('data.id');

        $res = fn (?string $partner, array $lines, ?string $date = null) => $api()->postJson('/api/tarifs/resolve', ['partner_id' => $partner, 'date' => $date, 'lines' => $lines])->assertOk()->json('data');
        $r = $res($grossiste, [['product_id' => $riz, 'quantity' => 10], ['product_id' => $riz, 'quantity' => 60], ['product_id' => $huile['id'], 'quantity' => 1], ['product_id' => $eau, 'quantity' => 1],
            ['product_id' => $huile['id'], 'quantity' => 2, 'packaging' => 'Carton de 12'], ['product_id' => $huile['id'], 'quantity' => 1, 'packaging' => 'Pack de 6']]);
        $this->assertEquals([18000, 0], [$r[0]['unit_price'], $r[0]['discount_percent']]);
        $this->assertEquals(17000, $r[1]['unit_price']);
        $this->assertStringContainsString('Grossistes (dès 50)', $r[1]['label']);
        $this->assertEquals([3000, 5], [$r[2]['unit_price'], $r[2]['discount_percent']]);
        $this->assertEquals([400, 2], [$r[3]['unit_price'], $r[3]['discount_percent']]);
        $this->assertEquals([33000, 5], [$r[4]['unit_price'], $r[4]['discount_percent']]); // prix du carton
        $this->assertEquals(18000, $r[5]['unit_price']); // pack : 6 × 3 000
        $p = $res($particulier, [['product_id' => $riz, 'quantity' => 1]]);
        $this->assertEquals(3, $p[0]['discount_percent']); // liste par défaut
        $this->assertEquals(0, $res($particulier, [['product_id' => $riz, 'quantity' => 1]], now()->addDays(30)->toDateString())[0]['discount_percent']); // promo terminée
        // Une seule liste par défaut
        $this->ok($push('pl_lists', ['name' => 'Autre défaut', 'is_default' => true, 'items' => []]));
        $this->assertFalse(\App\Modules\Tarifs\Models\PriceList::find($promo)->is_default);

        // Vente en cartons : le stock sort à l'unité ; rapports en unités
        $this->ok($push('stock_moves', ['product_id' => $huile['id'], 'warehouse_id' => $wh, 'quantity' => 100, 'type' => 'initial', 'date' => now()->toDateString()]));
        $this->ok($push('sales_documents', ['type' => 'invoice', 'partner_id' => $grossiste, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $huile['id'], 'description' => 'Huile 1 L — Carton de 12', 'unit' => 'Carton de 12', 'unit_factor' => 12, 'quantity' => 2, 'unit_price' => 33000, 'discount_percent' => 5, 'tax_rate' => 0]]]));
        $this->assertEquals(76, (float) StockMove::where('product_id', $huile['id'])->sum('quantity'));
        $rep = $api()->postJson('/api/rapports/preview', ['source' => 'ventes_articles', 'config' => ['measures' => ['quantity', 'subtotal', 'margin'], 'period' => 'all']])->json('data.totals');
        $this->assertEquals(24, $rep['quantity']);
        $this->assertEquals(62700 - 24 * 2400, $rep['margin']);
        // Achat en cartons : coût unitaire de stock = prix du carton / 12
        $sup = $api()->postJson('/api/core/partners', ['name' => 'Sifca', 'type' => 'supplier'])->json('data.id');
        $this->ok($push('purchase_documents', ['type' => 'supplier_invoice', 'partner_id' => $sup, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $huile['id'], 'description' => 'Huile', 'unit_factor' => 12, 'quantity' => 5, 'unit_price' => 27600, 'tax_rate' => 0]]]));
        $m = StockMove::where('product_id', $huile['id'])->where('type', 'purchase')->first();
        $this->assertEquals([60, 2300], [$m->quantity, $m->unit_cost]);

        // Suppression de la liste : les clients reviennent à la liste par défaut
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'pl_lists', 'op' => 'delete', 'record_id' => $gros]]])->assertOk();
        $this->assertNull(\App\Modules\Core\Models\Partner::find($grossiste)->price_list_id);
        $this->assertCount(2, $api()->getJson('/api/sync/pull?cursor=0')->json('changes.pl_lists.upserts'));
    }

    public function test_variants_generation_extras_propagation_rules_and_reports(): void
    {
        ['api' => $api, 'push' => $push, 'wh' => $wh] = $this->env();
        $tpl = $this->uuid();
        $this->ok($push('products', ['name' => 'T-shirt Yomara', 'sku' => 'TSY', 'type' => 'product', 'sale_price' => 5000, 'purchase_price' => 2500, 'tax_rate' => 18, 'track_stock' => true,
            'is_template' => true, 'variant_attributes' => [['name' => 'Taille', 'values' => ['S', 'M', 'XL', 'M']], ['name' => 'Couleur', 'values' => ['Blanc', 'Noir']]],
            'price_extras' => ['Taille' => ['XL' => 1000, 'S' => 0]]], $tpl));
        $t = Product::find($tpl);
        $this->assertFalse($t->track_stock);
        $this->assertSame(['S', 'M', 'XL'], $t->variant_attributes[0]['values']);
        $this->assertSame(['Taille' => ['XL' => 1000]], $t->price_extras);
        $this->assertSame('rejected', $push('products', ['variant_attributes' => [['name' => 'A', 'values' => range(1, 10)], ['name' => 'B', 'values' => range(1, 10)], ['name' => 'C', 'values' => range(1, 10)]]], $tpl)['status']);

        // Génération : toutes les combinaisons, référence et supplément ; pas de doublon
        $gen = $api()->postJson("/api/variantes/templates/$tpl/generate")->assertCreated()->json('data');
        $this->assertCount(6, $gen);
        $xl = collect($gen)->firstWhere('variant_values', ['Taille' => 'XL', 'Couleur' => 'Noir']);
        $this->assertSame('T-shirt Yomara — XL / Noir', $xl['name']);
        $this->assertSame('TSY-XL-NOIR', $xl['sku']);
        $this->assertEquals(6000, $xl['sale_price']);
        $this->assertSame($tpl, $xl['parent_id']);
        $this->assertCount(0, $api()->postJson("/api/variantes/templates/$tpl/generate")->json('data'));

        // Règles : valeur utilisée non supprimable, modèle qui reste modèle, parent non modifiable par le client
        $r = $push('products', ['variant_attributes' => [['name' => 'Taille', 'values' => ['S', 'M']], ['name' => 'Couleur', 'values' => ['Blanc', 'Noir']]]], $tpl);
        $this->assertStringContainsString('« Taille : XL » est utilisé par la variante', $r['message']);
        $this->assertSame('rejected', $push('products', ['is_template' => false], $tpl)['status']);
        $this->ok($push('products', ['parent_id' => null, 'variant_values' => ['Taille' => 'S']], $xl['id']));
        $this->assertSame($tpl, Product::find($xl['id'])->parent_id);
        $this->assertSame(['Taille' => 'XL', 'Couleur' => 'Noir'], Product::find($xl['id'])->variant_values);
        $this->assertSame('rejected', $push('products', ['is_template' => true], $xl['id'])['status']);

        // Nouvelle valeur : seules les combinaisons manquantes sont créées
        $this->ok($push('products', ['variant_attributes' => [['name' => 'Taille', 'values' => ['S', 'M', 'XL', 'XXL']], ['name' => 'Couleur', 'values' => ['Blanc', 'Noir']]],
            'price_extras' => ['Taille' => ['XL' => 1000, 'XXL' => 1500]]], $tpl));
        $this->assertCount(2, $api()->postJson("/api/variantes/templates/$tpl/generate")->json('data'));

        // Recopie : nouveau prix du modèle, suppléments conservés
        $this->ok($push('products', ['sale_price' => 5500, 'tax_rate' => 0], $tpl));
        $api()->postJson("/api/variantes/templates/$tpl/propagate", ['fields' => ['sale_price', 'tax_rate']])->assertOk()->assertJsonPath('data.updated', 8);
        $this->assertEquals([6500, 0], [Product::find($xl['id'])->sale_price, Product::find($xl['id'])->tax_rate]);
        $s = Product::where('parent_id', $tpl)->get()->first(fn ($p) => $p->variant_values['Taille'] === 'S');
        $this->assertEquals(5500, $s->sale_price);

        // Stock et ventes regroupés par modèle
        $this->ok($push('stock_moves', ['product_id' => $xl['id'], 'warehouse_id' => $wh, 'quantity' => 10, 'type' => 'initial', 'date' => now()->toDateString()]));
        $this->ok($push('stock_moves', ['product_id' => $s->id, 'warehouse_id' => $wh, 'quantity' => 4, 'type' => 'initial', 'date' => now()->toDateString()]));
        $show = $api()->getJson("/api/variantes/templates/$tpl")->assertOk()->json('data');
        $this->assertSame(8, $show['combinations']);
        $this->assertEquals(10, collect($show['variants'])->firstWhere('id', $xl['id'])['stock']);
        $this->ok($push('sales_documents', ['type' => 'invoice', 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [
            ['product_id' => $xl['id'], 'description' => 'XL', 'quantity' => 2, 'unit_price' => 6500, 'tax_rate' => 0],
            ['product_id' => $s->id, 'description' => 'S', 'quantity' => 1, 'unit_price' => 5500, 'tax_rate' => 0]]]));
        $rows = $api()->postJson('/api/rapports/preview', ['source' => 'ventes_articles', 'config' => ['dimensions' => ['template'], 'measures' => ['quantity', 'subtotal'], 'period' => 'all']])->json('data.rows');
        $this->assertSame('T-shirt Yomara', $rows[0]['labels']['template']);
        $this->assertEquals([3, 18500], [$rows[0]['values']['quantity'], $rows[0]['values']['subtotal']]);
        $st = $api()->postJson('/api/rapports/preview', ['source' => 'stock', 'config' => ['dimensions' => ['template'], 'measures' => ['quantity'], 'period' => 'all']])->json('data.rows');
        $this->assertEquals(11, $st[0]['values']['quantity']);
    }
}
