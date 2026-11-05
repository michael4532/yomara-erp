<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Comptabilité « niveau cabinet » : circuit des factures fournisseurs, natures d'achat, tiers, proforma, états SYSCOHADA. */
class ComptaCabinetTest extends TestCase
{
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->token] = $this->makeCompany('Cabinet Kouamé');
    }

    protected function api2()
    {
        return $this->api($this->token);
    }

    protected function supplierInvoice(string $supplier, array $lines): array
    {
        $doc = $this->api2()->postJson('/api/achats/documents', ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'date' => now()->toDateString(), 'lines' => $lines])->assertCreated()->json('data');

        return $this->api2()->postJson("/api/achats/documents/{$doc['id']}/validate")->assertOk()->json('data');
    }

    protected function entryLines(string $docNumber): array
    {
        $e = collect($this->api2()->getJson('/api/compta/entries?journal=AC&to='.now()->addDay()->toDateString())->json('data'))->firstWhere('reference', $docNumber);

        return $e ? collect($e['lines'])->mapWithKeys(fn ($l) => [$l['account']['code'] => $l['debit'] ?: -$l['credit']])->all() : [];
    }

    public function test_purchase_review_natures_and_memorised_accounts(): void
    {
        $api = fn () => $this->api2();
        $api()->putJson('/api/compta/settings', ['purchase_review' => true])->assertOk()->assertJsonPath('purchase_review', true);
        $flyers = $api()->postJson('/api/core/products', ['name' => 'Flyers A5 (1000 ex.)', 'sale_price' => 0, 'purchase_price' => 50000, 'tax_rate' => 18])->json('data.id');
        $laptop = $api()->postJson('/api/core/products', ['name' => 'Ordinateur portable', 'sale_price' => 0, 'purchase_price' => 400000, 'tax_rate' => 18])->json('data.id');
        $printer = $api()->postJson('/api/core/partners', ['name' => 'Imprimerie du Plateau', 'type' => 'supplier'])->json('data.id');
        $cie = $api()->postJson('/api/core/partners', ['name' => 'CIE', 'type' => 'supplier'])->json('data.id');

        // Facture validée : en attente de la comptabilité, aucune écriture
        $inv = $this->supplierInvoice($printer, [['product_id' => $flyers, 'description' => 'Flyers A5', 'quantity' => 1, 'unit_price' => 50000, 'tax_rate' => 18]]);
        $this->assertSame([], $this->entryLines($inv['number']));
        $this->assertSame('to_review', \App\Modules\Achats\Models\PurchaseDocument::find($inv['id'])->acc_status);
        $list = $api()->getJson('/api/compta/purchases?status=to_review')->assertOk()->json();
        $this->assertCount(1, $list['data']);
        $line = $list['data'][0]['lines'][0];
        $this->assertSame(['601', 'default', 'Marchandises (revente)'], [$line['account_code'], $line['account_source'], $line['account_label']]);
        $this->assertSame(1, $api()->getJson('/api/compta/setup')->json('to_review'));

        // Refus motivé, réponse des achats, retour en vérification
        $api()->postJson("/api/compta/purchases/{$inv['id']}/decide", ['decision' => 'refuse'])->assertStatus(422);
        $api()->postJson("/api/compta/purchases/{$inv['id']}/decide", ['decision' => 'refuse', 'note' => 'Bon de commande manquant'])->assertOk()->assertJsonPath('data.acc_status', 'refused');
        $api()->postJson("/api/compta/purchases/{$inv['id']}/decide", ['decision' => 'post', 'lines' => [$line['id'] => '411']])->assertStatus(422);
        $back = $api()->postJson("/api/compta/purchases/{$inv['id']}/resubmit", ['reply' => 'Commande passée par téléphone, validée par le DG'])->assertOk()->json('data');
        $this->assertSame('to_review', $back['acc_status']);
        $this->assertStringContainsString('validée par le DG', $back['acc_note']);

        // Comptabilisation en publicité (627) : D 627 / D 4452 / C 401
        $posted = $api()->postJson("/api/compta/purchases/{$inv['id']}/decide", ['decision' => 'post', 'lines' => [$line['id'] => '627']])->assertOk()->json('data');
        $this->assertSame('posted', $posted['acc_status']);
        $this->assertNotNull($posted['entry']);
        $this->assertEquals(['627' => 50000, '4452' => 9000, '401' => -59000], $this->entryLines($inv['number']));

        // Le compte est mémorisé pour l'article
        $inv2 = $this->supplierInvoice($printer, [['product_id' => $flyers, 'description' => 'Flyers A5', 'quantity' => 2, 'unit_price' => 50000, 'tax_rate' => 18]]);
        $l2 = collect($api()->getJson('/api/compta/purchases')->json('data'))->firstWhere('id', $inv2['id'])['lines'][0];
        $this->assertSame(['627', 'product'], [$l2['account_code'], $l2['account_source']]);

        // Électricité sans article : approuvée en 6052, puis comptabilisation groupée ; mémorisé pour le fournisseur
        $elec = $this->supplierInvoice($cie, [['description' => 'Facture électricité octobre', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 18]]);
        $el = collect($api()->getJson('/api/compta/purchases')->json('data'))->firstWhere('id', $elec['id'])['lines'][0];
        $this->assertSame(['601', 'default'], [$el['account_code'], $el['account_source']]);
        $api()->postJson("/api/compta/purchases/{$elec['id']}/decide", ['decision' => 'approve', 'lines' => [$el['id'] => '6052']])->assertOk()->assertJsonPath('data.acc_status', 'approved');
        $api()->postJson("/api/compta/purchases/{$inv2['id']}/decide", ['decision' => 'approve'])->assertOk();
        $this->assertSame([], $this->entryLines($elec['number']));
        $this->assertEquals(['posted' => 2, 'errors' => []], $api()->postJson('/api/compta/purchases/post-approved')->assertOk()->json('data'));
        $this->assertEquals(['6052' => 100000, '4452' => 18000, '401' => -118000], $this->entryLines($elec['number']));
        $elec2 = $this->supplierInvoice($cie, [['description' => 'Facture électricité novembre', 'quantity' => 1, 'unit_price' => 80000, 'tax_rate' => 18]]);
        $this->assertSame('supplier', collect($api()->getJson('/api/compta/purchases')->json('data'))->firstWhere('id', $elec2['id'])['lines'][0]['account_source']);

        // Immobilisation : matériel informatique, TVA récupérable sur immobilisations (4451)
        $pc = $this->supplierInvoice($printer, [['product_id' => $laptop, 'description' => 'Ordinateur', 'quantity' => 1, 'unit_price' => 400000, 'tax_rate' => 18, 'account_code' => '2442']]);
        $api()->postJson("/api/compta/purchases/{$pc['id']}/decide", ['decision' => 'post'])->assertOk();
        $this->assertEquals(['2442' => 400000, '4451' => 72000, '401' => -472000], $this->entryLines($pc['number']));

        // Règles : catégorie → compte, et liste
        $cat = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'product_categories', 'op' => 'upsert', 'record_id' => $catId = $this->uuid(), 'data' => ['name' => 'Entretien']]]])->json('results.0.status');
        $this->assertSame('applied', $cat);
        $rules = $api()->postJson('/api/compta/rules', ['scope' => 'category', 'ref_id' => $catId, 'account_code' => '624'])->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['product', 'supplier', 'category'], collect($rules)->pluck('scope')->unique()->values()->all());
        $api()->postJson('/api/compta/rules', ['scope' => 'category', 'ref_id' => $catId, 'account_code' => '701'])->assertStatus(422);

        // Circuit désactivé : comptabilisation immédiate, statut « comptabilisée »
        $api()->putJson('/api/compta/settings', ['purchase_review' => false])->assertOk();
        $direct = $this->supplierInvoice($printer, [['product_id' => $flyers, 'description' => 'Flyers', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 0]]);
        $this->assertEquals(['627' => 10000, '401' => -10000], $this->entryLines($direct['number']));
        $this->assertSame('posted', \App\Modules\Achats\Models\PurchaseDocument::find($direct['id'])->acc_status);
    }

    public function test_tiers_proforma_statements_exports_and_dashboard(): void
    {
        $api = fn () => $this->api2();
        $goods = $api()->postJson('/api/core/products', ['name' => 'Câble 2,5 mm²', 'sale_price' => 20000, 'purchase_price' => 12000, 'tax_rate' => 18])->json('data.id');
        $client = $api()->postJson('/api/core/partners', ['name' => 'Électricité Bamba', 'type' => 'customer'])->json('data.id');
        $client2 = $api()->postJson('/api/core/partners', ['name' => 'Hôtel Ivoire Lagune', 'type' => 'customer'])->json('data.id');
        $supplier = $api()->postJson('/api/core/partners', ['name' => 'Nexans CI', 'type' => 'supplier'])->json('data.id');

        // Proforma : numéro PRO, ni écriture ni créance ; transformée en facture définitive
        $pf = $api()->postJson('/api/ventes/documents', ['type' => 'proforma', 'partner_id' => $client, 'date' => now()->toDateString(),
            'lines' => [['product_id' => $goods, 'description' => 'Câble', 'quantity' => 10, 'unit_price' => 20000, 'tax_rate' => 18]]])->assertCreated()->json('data');
        $pf = $api()->postJson("/api/ventes/documents/{$pf['id']}/validate")->assertOk()->json('data');
        $this->assertStringStartsWith('PRO-', $pf['number']);
        $this->assertSame([], $api()->getJson('/api/compta/entries?journal=VE')->json('data'));
        $inv = $api()->postJson("/api/ventes/documents/{$pf['id']}/convert", ['type' => 'invoice'])->assertCreated()->json('data');
        $this->assertSame($pf['id'], $inv['source_id']);
        $inv = $api()->postJson("/api/ventes/documents/{$inv['id']}/validate")->assertOk()->json('data');
        $this->assertEquals(236000, $inv['total']);
        $api()->postJson('/api/ventes/payments', ['document_id' => $inv['id'], 'date' => now()->toDateString(), 'amount' => 100000, 'method' => 'mobile_money'])->assertCreated();
        $inv2 = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $client2, 'date' => now()->toDateString(),
            'lines' => [['product_id' => $goods, 'description' => 'Câble', 'quantity' => 5, 'unit_price' => 20000, 'tax_rate' => 18]]])->json('data');
        $api()->postJson("/api/ventes/documents/{$inv2['id']}/validate")->assertOk();
        $pi = $api()->postJson('/api/achats/documents', ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'date' => now()->toDateString(),
            'lines' => [['product_id' => $goods, 'description' => 'Câble', 'quantity' => 20, 'unit_price' => 12000, 'tax_rate' => 18]]])->json('data');
        $api()->postJson("/api/achats/documents/{$pi['id']}/validate")->assertOk();
        $api()->postJson('/api/achats/payments', ['document_id' => $pi['id'], 'date' => now()->toDateString(), 'amount' => 200000, 'method' => 'bank_transfer'])->assertCreated();

        // Comptes tiers : codes auxiliaires, balance et grand livre
        $cust = $api()->getJson('/api/compta/tiers?side=customers')->assertOk()->json('data');
        $this->assertSame(['CLI00001', 'CLI00002'], array_column($cust['rows'], 'aux_code'));
        $this->assertEquals([136000, 118000], array_column($cust['rows'], 'due'));
        $this->assertEquals(254000, $cust['totals']['due']);
        $supp = $api()->getJson('/api/compta/tiers?side=suppliers')->json('data');
        $this->assertSame('FRS00001', $supp['rows'][0]['aux_code']);
        $this->assertEquals(83200, $supp['rows'][0]['due']);
        $gl = $api()->getJson("/api/compta/tiers/$client?side=customers")->assertOk()->json('data');
        $this->assertCount(2, $gl['rows']);
        $this->assertEquals(136000, $gl['closing']);
        $csv = $api()->get('/api/compta/export')->assertOk()->getContent();
        $this->assertStringContainsString('Compte tiers', $csv);
        $this->assertStringContainsString('CLI00001', $csv);

        // Compte de résultat SYSCOHADA : marge commerciale, chiffre d'affaires, valeur ajoutée, résultat net
        $is = collect($api()->getJson('/api/compta/statements/income')->assertOk()->json('data.rows'))->keyBy('ref');
        $this->assertEquals(300000, $is['TA']['n']);
        $this->assertEquals(240000, $is['RA']['n']);
        $this->assertEquals(60000, $is['XA']['n']);
        $this->assertEquals(300000, $is['XB']['n']);
        $this->assertEquals(60000, $is['XI']['n']);
        $this->assertEquals(0, $is['XI']['n1']);

        // Bilan équilibré : clients, fournisseurs, dettes fiscales, trésorerie, résultat
        $bs = $api()->getJson('/api/compta/statements/sheet')->assertOk()->json('data');
        $this->assertTrue($bs['balanced'], json_encode($bs));
        $a = collect($bs['assets'])->keyBy('ref');
        $l = collect($bs['liabilities'])->keyBy('ref');
        $this->assertEquals(254000, $a['BI']['net']);
        $this->assertEquals(100000, $a['BS']['net']);
        $this->assertEquals(60000, $l['CJ']['n']);
        $this->assertEquals(83200, $l['DJ']['n']);
        $this->assertEquals(200000, $l['DR']['n']); // virement fournisseur : banque créditrice
        $this->assertEquals($a['BZ']['net'], $l['DZ']['n']);

        // Flux de trésorerie : la variation expliquée égale la variation réelle
        $tft = $api()->getJson('/api/compta/statements/cashflow')->assertOk()->json('data');
        $this->assertTrue($tft['check']);
        $this->assertEquals(-100000, $tft['variation']);
        $this->assertEquals(-100000, collect($tft['rows'])->firstWhere('ref', 'ZB')['amount']);

        // Exports Excel
        foreach (['income', 'sheet', 'cashflow', 'tiers'] as $k) {
            $r = $api()->get("/api/compta/statements/$k/export?side=customers")->assertOk();
            $this->assertStringContainsString('.xlsx', (string) $r->headers->get('content-disposition'));
        }

        // Tableau de bord comptable
        $d = $api()->getJson('/api/compta/dashboard')->assertOk()->json('data');
        $this->assertEquals(300000, $d['kpis']['revenue']);
        $this->assertEquals(60000, $d['kpis']['result']);
        $this->assertEquals(-100000, $d['kpis']['treasury']);
        $this->assertEquals(254000, $d['kpis']['receivables']);
        $this->assertEquals(83200, $d['kpis']['payables']);
        $this->assertSame('Électricité Bamba', $d['debtors'][0]['name']);
        $this->assertCount(12, $d['months']);
        $this->assertEquals(300000, end($d['months'])['income']);
    }
}
