<?php

namespace Tests\Feature;

use App\Models\Plan;
use Tests\TestCase;

class ComptaTest extends TestCase
{
    protected string $token;

    protected int $companyId;

    protected string $goods;

    protected string $service;

    protected string $customer;

    protected string $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        [$company, , $this->token] = $this->makeCompany('Boutique Compta');
        $this->companyId = $company->id;
        $this->goods = $this->api($this->token)->postJson('/api/core/products', ['name' => 'Ciment 50kg', 'sale_price' => 5000, 'purchase_price' => 4000, 'tax_rate' => 18])->json('data.id');
        $this->service = $this->api($this->token)->postJson('/api/core/products', ['name' => 'Livraison', 'type' => 'service', 'sale_price' => 10000, 'tax_rate' => 18])->json('data.id');
        $this->customer = $this->api($this->token)->postJson('/api/core/partners', ['name' => 'BTP Bamba', 'type' => 'customer'])->json('data.id');
        $this->supplier = $this->api($this->token)->postJson('/api/core/partners', ['name' => 'Cimenterie SA', 'type' => 'supplier'])->json('data.id');
    }

    protected function today(): string
    {
        return now()->toDateString();
    }

    protected function sale(array $lines, string $type = 'invoice', ?string $partner = null): array
    {
        $doc = $this->api($this->token)->postJson('/api/ventes/documents', [
            'type' => $type, 'partner_id' => $partner ?? $this->customer, 'date' => $this->today(), 'lines' => $lines,
        ])->assertCreated()->json('data');

        return $this->api($this->token)->postJson("/api/ventes/documents/{$doc['id']}/validate")->assertOk()->json('data');
    }

    /** Solde (débit − crédit) d'un compte sur l'exercice. */
    protected function balanceOf(string $code): float
    {
        $to = now()->addDays(2)->toDateString();
        $rows = collect($this->api($this->token)->getJson("/api/compta/balance?to=$to")->assertOk()->json('data.rows'));

        return (float) ($rows->firstWhere('code', $code)['balance'] ?? 0);
    }

    protected function entriesFor(string $journal): array
    {
        $to = now()->addDays(2)->toDateString();

        return $this->api($this->token)->getJson("/api/compta/entries?journal=$journal&to=$to")->json('data');
    }

    public function test_sales_purchases_and_payments_produce_balanced_syscohada_entries(): void
    {
        // Facture : 10 sacs (50 000 HT) + livraison (10 000 HT), TVA 18 % → 70 800 TTC
        $inv = $this->sale([
            ['product_id' => $this->goods, 'description' => 'Ciment', 'quantity' => 10, 'unit_price' => 5000, 'tax_rate' => 18],
            ['product_id' => $this->service, 'description' => 'Livraison', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 18],
        ]);
        $this->assertEquals(70800, $inv['total']);

        $ve = $this->entriesFor('VE');
        $this->assertCount(1, $ve);
        $this->assertSame('VE-'.now()->year.'-00001', $ve[0]['number']);
        $lines = collect($ve[0]['lines'])->keyBy(fn ($l) => $l['account']['code']);
        $this->assertEquals(70800, $lines['411']['debit']);
        $this->assertSame($this->customer, $lines['411']['partner_id']);
        $this->assertEquals(50000, $lines['701']['credit']);
        $this->assertEquals(10000, $lines['706']['credit']);
        $this->assertEquals(10800, $lines['4431']['credit']);

        // Acompte Mobile Money 30 000 → journal MM : D 5215 / C 411
        $this->api($this->token)->postJson('/api/ventes/payments', ['document_id' => $inv['id'], 'date' => $this->today(), 'amount' => 30000, 'method' => 'mobile_money'])->assertCreated();
        $mm = $this->entriesFor('MM');
        $this->assertCount(1, $mm);
        $this->assertEquals(30000, collect($mm[0]['lines'])->firstWhere('account.code', '5215')['debit']);
        $this->assertEquals(40800, $this->balanceOf('411'), 'reste dû client');

        // Facture fournisseur 20 sacs × 4 000 HT → AC : D 601 80 000 / D 4452 14 400 / C 401 94 400
        $pi = $this->api($this->token)->postJson('/api/achats/documents', [
            'type' => 'supplier_invoice', 'partner_id' => $this->supplier, 'date' => $this->today(),
            'lines' => [['product_id' => $this->goods, 'description' => 'Ciment', 'quantity' => 20, 'unit_price' => 4000, 'tax_rate' => 18]],
        ])->json('data');
        $this->api($this->token)->postJson("/api/achats/documents/{$pi['id']}/validate")->assertOk();
        $this->assertEquals(80000, $this->balanceOf('601'));
        $this->assertEquals(14400, $this->balanceOf('4452'));
        $this->assertEquals(-94400, $this->balanceOf('401'));

        // Règlement fournisseur par virement → BQ : D 401 / C 521
        $this->api($this->token)->postJson('/api/achats/payments', ['document_id' => $pi['id'], 'date' => $this->today(), 'amount' => 94400, 'method' => 'bank_transfer'])->assertCreated();
        $this->assertEquals(0, $this->balanceOf('401'));
        $this->assertEquals(-94400, $this->balanceOf('521'));

        // Balance équilibrée
        $bal = $this->api($this->token)->getJson('/api/compta/balance')->json('data.totals');
        $this->assertEquals($bal['debit'], $bal['credit']);
        $this->assertEquals($bal['debit_balance'], $bal['credit_balance']);

        // Compte de résultat : 60 000 de produits − 80 000 d'achats = −20 000
        $is = $this->api($this->token)->getJson('/api/compta/income')->json('data');
        $this->assertEquals(60000, $is['total_produits']);
        $this->assertEquals(80000, $is['total_charges']);
        $this->assertEquals(-20000, $is['resultat']);

        // Bilan équilibré, résultat intégré aux capitaux propres
        $bs = $this->api($this->token)->getJson('/api/compta/balance-sheet')->json('data');
        $this->assertTrue($bs['equilibre'], json_encode($bs));
        $this->assertEquals(-20000, $bs['resultat']);
        $this->assertEquals(40800 + 30000 + 14400, $bs['actif']['creances'] + $bs['actif']['tresorerie']);

        // TVA du mois : 10 800 collectée − 14 400 déductible = crédit 3 600
        $vat = $this->api($this->token)->getJson('/api/compta/vat')->json('data');
        $this->assertEquals(10800, $vat['collected']);
        $this->assertEquals(14400, $vat['deductible']);
        $this->assertEquals(0, $vat['due']);
        $this->assertEquals(3600, $vat['credit']);
        $this->assertEquals(60000, collect($vat['sales_bases'])->firstWhere('rate', 18)['base']);

        // Grand livre 411 : solde progressif
        $acc411 = collect($this->api($this->token)->getJson('/api/compta/setup')->json('accounts'))->firstWhere('code', '411')['id'];
        $gl = $this->api($this->token)->getJson("/api/compta/ledger/$acc411")->json('data');
        $this->assertCount(2, $gl['rows']);
        $this->assertEquals(40800, $gl['closing']);

        // Tableau de bord
        $w = collect($this->api($this->token)->getJson('/api/dashboard')->json('data'))->keyBy('key');
        $this->assertEquals(30000 - 94400, $w['treasury']['value']);
        $this->assertEquals(-20000, $w['result_ytd']['value']);
    }

    public function test_cash_receipt_credit_note_and_cancellation(): void
    {
        // Ticket de caisse payé en espèces → VE + CA (D 571 / C 411)
        $wh = $this->api($this->token)->postJson('/api/stock/warehouses', ['name' => 'Boutique'])->json('data.id');
        $reg = $this->api($this->token)->postJson('/api/pos/registers', ['name' => 'Caisse 1', 'code' => 'C1', 'warehouse_id' => $wh])->json('data.id');
        $session = $this->uuid();
        $doc = $this->uuid();
        $this->api($this->token)->postJson('/api/sync/push', ['mutations' => [
            ['id' => $this->uuid(), 'entity' => 'pos_sessions', 'op' => 'upsert', 'record_id' => $session, 'data' => ['register_id' => $reg, 'opening_cash' => 0]],
            ['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $doc, 'data' => [
                'type' => 'receipt', 'status' => 'validated', 'date' => $this->today(), 'pos_session_id' => $session,
                'lines' => [['product_id' => $this->goods, 'description' => 'Ciment', 'quantity' => 2, 'unit_price' => 5000, 'tax_rate' => 18]],
            ]],
            ['id' => $this->uuid(), 'entity' => 'sales_payments', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['document_id' => $doc, 'date' => $this->today(), 'amount' => 11800, 'method' => 'cash']],
        ]])->assertOk();
        $this->assertEquals(11800, $this->balanceOf('571'));
        $this->assertEquals(0, $this->balanceOf('411'));
        $this->assertEquals(-10000, $this->balanceOf('701'));

        // Avoir : écriture inverse
        $this->sale([['product_id' => $this->goods, 'description' => 'Retour', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 18]], 'credit_note');
        $this->assertEquals(-5000, $this->balanceOf('701'));
        $this->assertEquals(-900, $this->balanceOf('4431'));
        $this->assertEquals(-5900, $this->balanceOf('411'));

        // Facture annulée : contre-passation, la facture n'a plus d'effet
        $inv = $this->sale([['product_id' => $this->goods, 'description' => 'Ciment', 'quantity' => 4, 'unit_price' => 5000, 'tax_rate' => 18]]);
        $this->assertEquals(-25000, $this->balanceOf('701'));
        $this->api($this->token)->postJson("/api/ventes/documents/{$inv['id']}/cancel")->assertOk();
        $this->assertEquals(-5000, $this->balanceOf('701'));
        $ve = collect($this->entriesFor('VE'));
        $this->assertNotNull($ve->firstWhere('kind', 'reversal'));
        $this->assertNotNull($ve->firstWhere(fn ($e) => $e['reversed_by_id'] !== null));
    }

    public function test_lock_date_manual_entries_and_reversal(): void
    {
        $setup = $this->api($this->token)->getJson('/api/compta/setup')->json();
        $acc = collect($setup['accounts'])->keyBy('code');
        $od = collect($setup['journals'])->firstWhere('code', 'OD')['id'];

        // Écriture déséquilibrée refusée
        $this->api($this->token)->postJson('/api/compta/entries', [
            'journal_id' => $od, 'date' => $this->today(), 'label' => 'Loyer',
            'lines' => [['account_id' => $acc['622']['id'], 'debit' => 150000], ['account_id' => $acc['521']['id'], 'credit' => 140000]],
        ])->assertStatus(422);

        // Écriture équilibrée acceptée
        $e = $this->api($this->token)->postJson('/api/compta/entries', [
            'journal_id' => $od, 'date' => $this->today(), 'label' => 'Loyer octobre',
            'lines' => [['account_id' => $acc['622']['id'], 'debit' => 150000], ['account_id' => $acc['521']['id'], 'credit' => 150000]],
        ])->assertCreated()->json('data');
        $this->assertEquals(150000, $this->balanceOf('622'));

        // Contre-passation
        $this->api($this->token)->postJson("/api/compta/entries/{$e['id']}/reverse")->assertOk();
        $this->assertEquals(0, $this->balanceOf('622'));
        $this->api($this->token)->postJson("/api/compta/entries/{$e['id']}/reverse")->assertStatus(422);

        // Paiement saisi, puis période verrouillée, puis paiement supprimé : contre-passation, pas d'effacement
        $inv = $this->sale([['product_id' => $this->goods, 'description' => 'Ciment', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 18]]);
        $pay = $this->api($this->token)->postJson('/api/ventes/payments', ['document_id' => $inv['id'], 'date' => $this->today(), 'amount' => 5900, 'method' => 'cash'])->json('data.id');
        $this->api($this->token)->putJson('/api/compta/settings', ['lock_date' => $this->today()])->assertOk()->assertJsonPath('lock_date', $this->today());

        $this->api($this->token)->postJson('/api/compta/entries', [
            'journal_id' => $od, 'date' => $this->today(), 'label' => 'Trop tard',
            'lines' => [['account_id' => $acc['622']['id'], 'debit' => 1000], ['account_id' => $acc['571']['id'], 'credit' => 1000]],
        ])->assertStatus(422)->assertJsonFragment(['message' => 'La période est verrouillée jusqu\'au '.now()->format('d/m/Y').'.']);

        $this->api($this->token)->deleteJson("/api/ventes/payments/$pay")->assertOk();
        $ca = collect($this->entriesFor('CA'));
        $this->assertCount(2, $ca, 'écriture d\'origine conservée + contre-passation');
        $this->assertSame(now()->addDay()->toDateString(), $ca->firstWhere('kind', 'reversal')['date']);
        $this->assertEquals(0, $this->balanceOf('571'));
    }

    public function test_module_disabled_then_enabled_with_history_rebuild_and_export(): void
    {
        [$super] = [$this->makeCompany('Yomara', 'entreprise', superAdmin: true)[2]];
        $this->api($super)->putJson("/api/admin/companies/{$this->companyId}/modules/compta", ['enabled' => false])->assertOk();

        $inv = $this->sale([['product_id' => $this->goods, 'description' => 'Ciment', 'quantity' => 3, 'unit_price' => 5000, 'tax_rate' => 18]]);
        $this->api($this->token)->postJson('/api/ventes/payments', ['document_id' => $inv['id'], 'date' => $this->today(), 'amount' => 17700, 'method' => 'card'])->assertCreated();
        $this->api($this->token)->getJson('/api/compta/balance')->assertStatus(403);
        $this->assertDatabaseCount('compta_entries', 0);

        // Activation : reprise de l'historique, idempotente
        $this->api($super)->putJson("/api/admin/companies/{$this->companyId}/modules/compta", ['enabled' => true])->assertOk();
        $this->api($this->token)->postJson('/api/compta/rebuild')->assertOk()->assertJsonPath('data', ['documents' => 1, 'payments' => 1]);
        $this->api($this->token)->postJson('/api/compta/rebuild')->assertOk()->assertJsonPath('data', ['documents' => 0, 'payments' => 0]);
        $this->assertEquals(17700, $this->balanceOf('521'), 'carte bancaire → banque');
        $this->assertEquals(0, $this->balanceOf('411'));

        // Sous-compte et paramétrage : Mobile Money vers un compte dédié
        $this->api($this->token)->postJson('/api/compta/accounts', ['code' => '52151', 'name' => 'Wave'])->assertCreated();
        $this->api($this->token)->postJson('/api/compta/accounts', ['code' => 'abc', 'name' => 'X'])->assertStatus(422);
        $this->api($this->token)->putJson('/api/compta/settings', ['mapping' => ['mobile_money' => '52151']])->assertOk()->assertJsonPath('mapping.mobile_money', '52151');
        $this->api($this->token)->putJson('/api/compta/settings', ['mapping' => ['mobile_money' => '99999']])->assertStatus(422);
        $inv2 = $this->sale([['product_id' => $this->goods, 'description' => 'Ciment', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 18]]);
        $this->api($this->token)->postJson('/api/ventes/payments', ['document_id' => $inv2['id'], 'date' => $this->today(), 'amount' => 5900, 'method' => 'mobile_money'])->assertCreated();
        $this->assertEquals(5900, $this->balanceOf('52151'));

        // Export CSV pour l'expert-comptable
        $csv = $this->api($this->token)->get('/api/compta/export')->assertOk()->getContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFJournal;Date;", $csv);
        $this->assertStringContainsString(';4431;', $csv);
        $this->assertStringContainsString('BTP Bamba', $csv);
    }
}
