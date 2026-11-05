<?php

namespace Tests\Feature;

use App\Modules\Compta\Models\Entry;
use Tests\TestCase;

class DevisesTest extends TestCase
{
    public function test_foreign_currency_documents_rates_fx_gains_losses_and_dashboards(): void
    {
        [$company, , $token] = $this->makeCompany('Export Cacao CI');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (array $m) => $api()->postJson('/api/sync/push', ['mutations' => array_map(fn ($x) => $x + ['id' => $this->uuid(), 'op' => 'upsert'], $m)])->assertOk()->json('results');
        $today = now()->toDateString();

        // Devises : euro à parité fixe, dollar selon les cours saisis
        $cur = $api()->getJson('/api/devises/currencies')->assertOk()->json('data');
        $this->assertSame('XOF', $cur['base']);
        $eur = collect($cur['currencies'])->firstWhere('code', 'EUR');
        $this->assertEquals(655.957, $eur['fixed']);
        $this->assertNull(collect($cur['currencies'])->firstWhere('code', 'USD')['rate']);
        $this->assertSame('rejected', $push([['entity' => 'fx_rates', 'record_id' => $this->uuid(), 'data' => ['currency' => 'EUR', 'date' => $today, 'rate' => 650]]])[0]['status']);
        $this->assertSame('rejected', $push([['entity' => 'fx_rates', 'record_id' => $this->uuid(), 'data' => ['currency' => 'XOF', 'date' => $today, 'rate' => 1]]])[0]['status']);
        $push([['entity' => 'fx_rates', 'record_id' => $this->uuid(), 'data' => ['currency' => 'USD', 'date' => now()->subDays(10)->toDateString(), 'rate' => 600]]]);
        $push([['entity' => 'fx_rates', 'record_id' => $this->uuid(), 'data' => ['currency' => 'USD', 'date' => $today, 'rate' => 610]]]);
        $this->assertEquals(600, $api()->getJson('/api/devises/rate?currency=USD&date='.now()->subDays(5)->toDateString())->json('data.rate'));

        // Client américain : devise par défaut sur la fiche
        $us = $api()->postJson('/api/core/partners', ['name' => 'Chocolate Corp USA', 'type' => 'customer', 'currency' => 'USD', 'country' => 'US'])->assertCreated()->json('data.id');
        $fr = $api()->postJson('/api/core/partners', ['name' => 'Cacao Paris SAS', 'type' => 'customer', 'country' => 'FR'])->assertCreated()->json('data.id');
        $line = fn (float $p, float $tax = 0) => ['id' => $this->uuid(), 'description' => 'Fèves de cacao grade I (t)', 'quantity' => 10, 'unit_price' => $p, 'tax_rate' => $tax];

        $inv = $this->uuid();
        $r = $push([['entity' => 'sales_documents', 'record_id' => $inv, 'data' => ['type' => 'invoice', 'partner_id' => $us, 'date' => now()->subDays(5)->toDateString(), 'status' => 'validated', 'lines' => [$line(2500)]]]])[0];
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame('USD', $r['record']['currency']);
        $this->assertEquals(600, $r['record']['exchange_rate']);
        $this->assertEquals(25000, $r['record']['total']);
        $this->assertEquals(2500, $r['record']['lines'][0]['unit_price']); // centimes conservés en devise
        $e = Entry::withoutGlobalScopes()->where('source_type', 'document')->where('source_id', $inv)->with('lines.account')->first();
        $this->assertEquals(15000000, $e->total); // 25 000 USD × 600

        // Paiement au cours du jour (610) : gain de change 10 × 25 000 = 250 000 F
        $api()->postJson('/api/ventes/payments', ['document_id' => $inv, 'date' => $today, 'amount' => 25000, 'method' => 'bank_transfer'])->assertCreated()->assertJsonPath('data.exchange_rate', 610);
        $p = Entry::withoutGlobalScopes()->where('source_type', 'payment')->with('lines.account')->latest('id')->first();
        $acc = $p->lines->groupBy('account.code')->map(fn ($g) => round($g->sum('debit') - $g->sum('credit')));
        $this->assertEquals(15250000, $acc['521']);
        $this->assertEquals(-15000000, $acc['411']);
        $this->assertEquals(-250000, $acc['776']);
        $this->assertSame('paid', $api()->getJson("/api/ventes/documents/$inv")->json('data.payment_status'));

        // Facture en euros (parité fixe) avec TVA, pour un client sans devise par défaut
        $eu = $this->uuid();
        $r = $push([['entity' => 'sales_documents', 'record_id' => $eu, 'data' => ['type' => 'invoice', 'partner_id' => $fr, 'currency' => 'EUR', 'date' => $today, 'status' => 'validated', 'lines' => [$line(99.99, 18)]]]])[0];
        $this->assertEquals(655.957, $r['record']['exchange_rate']);
        $this->assertEquals(1179.88, $r['record']['total']);
        $e = Entry::withoutGlobalScopes()->where('source_id', $eu)->with('lines.account')->first();
        $acc = $e->lines->groupBy('account.code')->map(fn ($g) => round($g->sum('debit') - $g->sum('credit')));
        $this->assertEquals(round(1179.88 * 655.957), $acc['411']);
        $this->assertEquals(-round(999.9 * 655.957), $acc['701']);
        $this->assertEquals(0, $acc->sum()); // équilibrée

        // Devise sans cours : refusée avec un message clair ; cours manuel sur le document accepté
        $r = $push([['entity' => 'sales_documents', 'record_id' => $this->uuid(), 'data' => ['type' => 'invoice', 'partner_id' => $fr, 'currency' => 'GBP', 'date' => $today, 'status' => 'validated', 'lines' => [$line(100)]]]])[0];
        $this->assertSame('rejected', $r['status']);
        $this->assertStringContainsString('Aucun cours connu pour GBP', $r['message']);
        $r = $push([['entity' => 'sales_documents', 'record_id' => $this->uuid(), 'data' => ['type' => 'invoice', 'partner_id' => $fr, 'currency' => 'GBP', 'exchange_rate' => 780, 'date' => $today, 'lines' => [$line(100)]]]])[0];
        $this->assertSame('applied', $r['status']);
        $this->assertEquals(780, $r['record']['exchange_rate']);
        // Document validé : devise figée
        $this->assertSame('rejected', $push([['entity' => 'sales_documents', 'record_id' => $eu, 'data' => ['currency' => 'USD']]])[0]['status']);

        // Achat en dollars payé plus cher : perte de change
        $sup = $api()->postJson('/api/core/partners', ['name' => 'Machines Inc', 'type' => 'supplier', 'currency' => 'USD'])->assertCreated()->json('data.id');
        $ff = $this->uuid();
        $push([['entity' => 'purchase_documents', 'record_id' => $ff, 'data' => ['type' => 'supplier_invoice', 'partner_id' => $sup, 'date' => now()->subDays(5)->toDateString(), 'status' => 'validated', 'lines' => [['description' => 'Pièces', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]]]]]);
        $api()->postJson('/api/achats/payments', ['document_id' => $ff, 'date' => $today, 'amount' => 1000, 'method' => 'bank_transfer'])->assertCreated();
        $p = Entry::withoutGlobalScopes()->where('source_type', 'payment')->with('lines.account')->latest('id')->first();
        $acc = $p->lines->groupBy('account.code')->map(fn ($g) => round($g->sum('debit') - $g->sum('credit')));
        $this->assertEquals(600000, $acc['401']);
        $this->assertEquals(-610000, $acc['521']);
        $this->assertEquals(10000, $acc['676']);

        // Tableau de bord : chiffre d'affaires en francs CFA
        $kpi = collect($api()->getJson('/api/dashboard')->json('kpis') ?? $api()->getJson('/api/dashboard')->json('data'))->firstWhere('key', 'revenue_month');
        $this->assertNotNull($kpi);
        $this->assertGreaterThan(now()->day > 5 ? 15000000 : 0, $kpi['value']); // facture datée de J-5 : hors du mois en début de mois

        // Liste des devises proposées modifiable
        $api()->putJson('/api/devises/currencies', ['currencies' => ['EUR', 'USD', 'GBP']])->assertOk();
        $this->assertCount(4, $api()->getJson('/api/devises/currencies')->json('data.currencies'));
    }

    public function test_foreign_currency_refused_without_module(): void
    {
        config(['erp.mode' => 'saas']);
        [, , $token] = $this->makeCompany('Petit Commerce', 'starter');
        $r = $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(),
            'data' => ['type' => 'invoice', 'currency' => 'EUR', 'date' => now()->toDateString(), 'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 10]]]]]])->json('results.0');
        $this->assertSame('rejected', $r['status']);
        $this->assertStringContainsString('Activez le module Devises', $r['message']);
    }
}
