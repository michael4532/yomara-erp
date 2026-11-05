<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Consolidation : rattachement par code, élimination des opérations réciproques, titres, intérêts minoritaires, mise en équivalence. */
class ComptaGroupTest extends TestCase
{
    protected function seedCompany(string $token, int $y, float $capital, array $sales, array $purchases, array $extra = []): void
    {
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $setup = $api()->getJson('/api/compta/setup')->assertOk()->json();
        $acc = collect($setup['accounts'])->keyBy('code');
        $od = collect($setup['journals'])->firstWhere('code', 'OD')['id'];
        $api()->postJson('/api/compta/entries', ['journal_id' => $od, 'date' => $y.'-01-02', 'label' => 'Apport en capital', 'lines' => [
            ['account_id' => $acc['521']['id'], 'debit' => $capital, 'credit' => 0], ['account_id' => $acc['101']['id'], 'debit' => 0, 'credit' => $capital]]])->assertCreated();
        foreach ($extra as [$debit, $credit, $amount, $label]) {
            $api()->postJson('/api/compta/entries', ['journal_id' => $od, 'date' => $y.'-01-05', 'label' => $label, 'lines' => [
                ['account_id' => $acc[$debit]['id'], 'debit' => $amount, 'credit' => 0], ['account_id' => $acc[$credit]['id'], 'debit' => 0, 'credit' => $amount]]])->assertCreated();
        }
        foreach ($sales as [$name, $ht, $date]) {
            $p = $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'customer'])->json('data.id');
            $res = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(),
                'data' => ['type' => 'invoice', 'partner_id' => $p, 'date' => $date, 'status' => 'validated', 'lines' => [['id' => $this->uuid(), 'description' => 'Marchandises', 'quantity' => 1, 'unit_price' => $ht, 'tax_rate' => 18, 'discount_percent' => 0]]]]]])->json('results.0');
            $this->assertSame('applied', $res['status'], json_encode($res));
        }
        foreach ($purchases as [$name, $ht, $date]) {
            $p = $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'supplier'])->json('data.id');
            $doc = $api()->postJson('/api/achats/documents', ['type' => 'supplier_invoice', 'partner_id' => $p, 'date' => $date, 'reference' => 'F-'.random_int(100, 999),
                'lines' => [['description' => 'Marchandises', 'quantity' => 1, 'unit_price' => $ht, 'tax_rate' => 18]]])->assertCreated()->json('data');
            $api()->postJson("/api/achats/documents/{$doc['id']}/validate")->assertOk();
        }
    }

    public function test_group_consolidation_full_and_equity_method(): void
    {
        [$parent, , $pt] = $this->makeCompany('Holding Kouassi');
        [$sub, , $st] = $this->makeCompany('Kouassi Distribution');
        [, , $ot] = $this->makeCompany('Autre Groupe');
        $y = (int) now()->year - 1;

        // Mère : capital 50 M, titres de la filiale 8 M (80 %), ventes externes 50 M, achats à la filiale 20 M
        $this->seedCompany($pt, $y, 50000000, [['Client externe', 50000000, $y.'-04-10']], [['Kouassi Distribution', 20000000, $y.'-05-10']], [['261', '521', 8000000, 'Acquisition de 80 % de Kouassi Distribution']]);
        // Filiale : capital 10 M, ventes à la mère 20 M et à l'extérieur 30 M
        $this->seedCompany($st, $y, 10000000, [['Holding Kouassi', 20000000, $y.'-05-10'], ['Client externe', 30000000, $y.'-06-10']], []);

        // Rattachement : la filiale génère un code, la mère le saisit
        $this->api($pt)->postJson('/api/compta/group/members', ['code' => 'ABCD-EFGH'])->assertStatus(422);
        $code = $this->api($st)->postJson('/api/compta/group/link-code')->assertOk()->json('data.code');
        $this->api($st)->getJson('/api/compta/group')->assertOk()->assertJsonPath('data.link_code.code', $code);
        $this->api($st)->postJson('/api/compta/group/members', ['code' => $code])->assertStatus(422); // à soi-même
        $m = $this->api($pt)->postJson('/api/compta/group/members', ['code' => $code, 'interest_pct' => 80, 'control_pct' => 80, 'method' => 'ig', 'investment_cost' => 8000000,
            'equity_at_acquisition' => 10000000, 'acquisition_date' => $y.'-01-05'])->assertOk()->json('data');
        $this->assertSame('Kouassi Distribution', $m['name']);
        $this->assertSame('Intégration globale', $m['method_label']);
        $this->api($ot)->postJson('/api/compta/group/members', ['code' => $code])->assertStatus(422); // code consommé
        $this->assertSame('Holding Kouassi', $this->api($st)->getJson('/api/compta/group')->json('data.parent.name'));
        $this->assertCount(1, $this->api($pt)->getJson('/api/compta/group')->json('data.members'));

        // Intégration globale
        $s = $this->api($pt)->getJson("/api/compta/consolidation?from=$y-01-01&to=$y-12-31")->assertOk()->json('data');
        $sum = $s['summary'];
        $this->assertEquals([20000000, 20000000, 23600000, 23600000], [$sum['intercompany_sales'], $sum['intercompany_purchases'], $sum['intercompany_receivables'], $sum['intercompany_payables']]);
        // 30 M (mère) + 50 M (filiale) ; ventes et achats internes éliminés sans effet sur le résultat
        $this->assertEquals([80000000, 10000000, 70000000, 2000000, 0.0], [$sum['result'], $sum['minority_result'], $sum['group_result'], $sum['minority_interests'], $sum['goodwill']]);
        $this->assertTrue($s['sheet']['balanced'], json_encode([$s['sheet']['total_assets'], $s['sheet']['total_liabilities'], $s['adjustments']]));
        // Chiffre d'affaires consolidé : 50 + 50 − 20 = 80 M
        $this->assertEquals(80000000, collect($s['income']['rows'])->firstWhere('key', 'TA')['n']);
        $this->assertEquals(12000000, collect($s['sheet']['liabilities'])->firstWhere('key', 'MI')['n']); // 20 % × (10 M + 50 M)
        $this->assertEquals(70000000, collect($s['sheet']['liabilities'])->firstWhere('key', 'CJ')['n']); // résultat part du groupe
        $this->assertEquals(70000000, collect($s['income']['rows'])->firstWhere('key', 'GRR')['n']);
        $this->assertSame([], $s['warnings']);
        $this->assertTrue($s['cashflow']['check'], json_encode($s['cashflow']));
        $close = collect($s['equity_changes']['rows'])->firstWhere('key', 'close');
        $this->assertEquals($s['summary']['group_equity'], $close['group']);
        $this->assertEquals(12000000, $close['minority']); // 20 % × (10 M + 50 M)
        $this->api($pt)->get("/api/compta/consolidation/xlsx?from=$y-01-01&to=$y-12-31")->assertOk();

        // Écart d'acquisition : titres payés 9 M pour 80 % de 10 M → 1 M à l'actif
        $this->api($pt)->putJson("/api/compta/group/members/{$m['id']}", ['investment_cost' => 9000000])->assertOk();
        $s = $this->api($pt)->getJson("/api/compta/consolidation?from=$y-01-01&to=$y-12-31")->json('data');
        $this->assertEquals(1000000, $s['summary']['goodwill']);
        $this->assertTrue($s['sheet']['balanced']);

        // Mise en équivalence : la filiale n'est plus cumulée, la mère prend 80 % de son résultat
        $this->api($pt)->putJson("/api/compta/group/members/{$m['id']}", ['method' => 'mee', 'investment_cost' => 8000000])->assertOk();
        $s = $this->api($pt)->getJson("/api/compta/consolidation?from=$y-01-01&to=$y-12-31")->json('data');
        $this->assertEquals([70000000, 0, 40000000, 0], [$s['summary']['result'], $s['summary']['minority_result'], $s['summary']['equity_method_result'], $s['summary']['minority_interests']]);
        $this->assertTrue($s['sheet']['balanced']);

        // Détachement par la filiale
        $this->api($st)->deleteJson("/api/compta/group/members/{$m['id']}")->assertOk();
        $this->api($pt)->getJson('/api/compta/consolidation')->assertStatus(422);
    }

    /** Saisie d'opérations diverses : [[compte, débit, crédit, partenaire?], …] */
    protected function od(string $token, string $date, string $label, array $lines): void
    {
        $api = $this->api($token);
        $setup = $api->getJson('/api/compta/setup')->assertOk()->json();
        $acc = collect($setup['accounts'])->keyBy('code');
        $od = collect($setup['journals'])->firstWhere('code', 'OD')['id'];
        $this->api($token)->postJson('/api/compta/entries', ['journal_id' => $od, 'date' => $date, 'label' => $label, 'lines' => array_map(fn ($l) => [
            'account_id' => $acc[$l[0]]['id'], 'debit' => $l[1], 'credit' => $l[2], 'partner_id' => $l[3] ?? null], $lines)])->assertCreated();
    }

    public function test_foreign_subsidiary_translation_dividends_stock_margin_manual_entry_freeze(): void
    {
        [$parent, , $pt] = $this->makeCompany('Holding Diallo');
        [$sub, , $st] = $this->makeCompany('Diallo Ghana');
        $sub->forceFill(['currency' => 'USD'])->save();
        $y = (int) now()->year - 1;
        $this->api($pt)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $this->api($st)->getJson('/api/sync/pull?cursor=0')->assertOk();

        // Filiale (USD) : capital 100 000, ventes 200 000, achats 120 000, dividende de 10 000 versé à la mère
        $this->od($st, $y.'-01-02', 'Capital', [['521', 100000, 0], ['101', 0, 100000]]);
        $this->od($st, $y.'-06-30', 'Ventes de l\'exercice', [['521', 200000, 0], ['701', 0, 200000]]);
        $this->od($st, $y.'-06-30', 'Achats de l\'exercice', [['601', 120000, 0], ['521', 0, 120000]]);
        $this->od($st, $y.'-07-15', 'Dividende versé à Holding Diallo', [['121', 10000, 0], ['521', 0, 10000]]);
        // Mère (XOF) : capital, titres 60 % pour 30 M, dividende reçu 5,8 M (10 000 USD au cours moyen 580)
        $subPartner = $this->api($pt)->postJson('/api/core/partners', ['name' => 'Diallo Ghana', 'type' => 'customer'])->json('data.id');
        $this->od($pt, $y.'-01-02', 'Capital', [['521', 100000000, 0], ['101', 0, 100000000]]);
        $this->od($pt, $y.'-01-03', 'Titres Diallo Ghana', [['261', 30000000, 0], ['521', 0, 30000000]]);
        $this->od($pt, $y.'-07-15', 'Dividende Diallo Ghana', [['521', 5800000, 0], ['772', 0, 5800000, $subPartner]]);

        $code = $this->api($st)->postJson('/api/compta/group/link-code')->json('data.code');
        $m = $this->api($pt)->postJson('/api/compta/group/members', ['code' => $code, 'interest_pct' => 60, 'control_pct' => 60, 'method' => 'ig', 'investment_cost' => 30000000,
            'equity_at_acquisition' => 100000, 'acquisition_date' => $y.'-01-03', 'rate' => 600, 'average_rate' => 580, 'historical_rate' => 550])->assertOk()->json('data');
        $this->assertTrue($m['foreign']);
        $this->assertEquals([600, 580, 550], [$m['rate'], $m['average_rate'], $m['historical_rate']]);

        $q = "from=$y-01-01&to=$y-12-31";
        $s = $this->api($pt)->getJson("/api/compta/consolidation?$q")->assertOk()->json('data');
        $sum = $s['summary'];
        // Résultat filiale 80 000 × 580 = 46,4 M ; dividende interne de 5,8 M éliminé chez la mère
        $this->assertEquals([46400000, 18560000, 5800000], [$sum['result'], $sum['minority_result'], $sum['intercompany_dividends']]);
        // Actif net 170 000 × 600 − capitaux propres 90 000 × 550 − résultat 46,4 M = 6,1 M d'écart, 60 % au groupe
        $this->assertEquals(6100000, collect($s['perimeter'])->firstWhere('name', 'Diallo Ghana')['translation']);
        $this->assertEquals(3660000, $sum['translation_difference']);
        $this->assertEquals(22240000, $sum['minority_interests']); // 40 % × (49,5 M + 6,1 M)
        $this->assertEquals(3660000, collect($s['sheet']['liabilities'])->firstWhere('key', 'TD')['n']);
        $this->assertTrue($s['sheet']['balanced'], json_encode([$s['sheet']['total_assets'], $s['sheet']['total_liabilities']]));

        $this->api($pt)->putJson('/api/compta/group/settings', ['deferred_tax_rate' => 25])->assertOk()->assertJsonPath('data.deferred_tax_rate', 25);
        // Marge interne : la mère détient 4 M de marchandises achetées à la filiale ; taux de marge calculé (40 %), impôt différé 25 %
        $this->api($pt)->postJson('/api/compta/group/adjustments', ['to' => "$y-12-31", 'kind' => 'stock_margin', 'seller_id' => $sub->id, 'buyer_id' => $parent->id, 'amount' => 4000000])->assertOk()
            ->assertJsonPath('data.0.margin_pct', 40);
        // Écriture manuelle : déséquilibrée refusée, puis charge de 0,5 M
        $this->api($pt)->postJson('/api/compta/group/adjustments', ['to' => "$y-12-31", 'kind' => 'entry', 'lines' => [['account' => '622', 'debit' => 500000], ['account' => '401', 'credit' => 400000]]])->assertStatus(422);
        $this->api($pt)->postJson('/api/compta/group/adjustments', ['to' => "$y-12-31", 'kind' => 'entry', 'label' => 'Loyer intragroupe non comptabilisé', 'lines' => [['account' => '622', 'debit' => 500000], ['account' => '401', 'credit' => 500000]]])->assertOk();
        $r = $this->api($pt)->getJson("/api/compta/consolidation?$q")->json();
        $this->assertCount(2, $r['adjustments']);
        $sum = $r['data']['summary'];
        // 46,4 M − marge 1,6 M + impôt différé 0,4 M − charge 0,5 M ; minoritaires 40 % × (46,4 M − 1,2 M)
        $this->assertEquals([1600000, 400000, 44700000, 18080000], [$sum['stock_margin'], $sum['deferred_tax'], $sum['result'], $sum['minority_result']]);
        $this->assertTrue($r['data']['cashflow']['check']);
        $this->assertEquals($sum['group_equity'], collect($r['data']['equity_changes']['rows'])->firstWhere('key', 'close')['group']);
        $this->assertTrue($r['data']['sheet']['balanced']);
        $this->assertStringStartsWith('%PDF', $this->api($pt)->get("/api/compta/consolidation/pdf?$q")->getContent());

        // Arrêté des comptes consolidés : figés, retraitements verrouillés
        $f = $this->api($pt)->postJson('/api/compta/consolidation/freeze', ['from' => "$y-01-01", 'to' => "$y-12-31"])->assertOk()->json('data');
        $this->assertNotNull($f['frozen']);
        $this->api($pt)->postJson('/api/compta/group/adjustments', ['to' => "$y-12-31", 'kind' => 'dividend', 'seller_id' => $sub->id, 'buyer_id' => $parent->id, 'amount' => 1000])->assertStatus(422);
        $this->od($st, $y.'-12-20', 'Vente tardive', [['521', 1000, 0], ['701', 0, 1000]]);
        $this->assertEquals(44700000, $this->api($pt)->getJson("/api/compta/consolidation?$q")->json('data.summary.result'));
        $this->assertEquals(45280000, $this->api($pt)->getJson("/api/compta/consolidation?$q&fresh=1")->json('data.summary.result'));
        $u = $this->api($pt)->postJson('/api/compta/consolidation/unfreeze', ['from' => "$y-01-01", 'to' => "$y-12-31"])->assertOk()->json('data');
        $this->assertNull($u['frozen']);
        $this->assertEquals(45280000, $u['summary']['result']);
    }

    public function test_goodwill_amortization_margin_reversal_cashflow_and_equity_changes(): void
    {
        [$parent, , $pt] = $this->makeCompany('Groupe Konan');
        [$sub, , $st] = $this->makeCompany('Konan Négoce');
        $y = (int) now()->year - 1; // exercice N ; N-1 = $y - 1
        $p = $y - 1;
        $this->api($pt)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $this->api($st)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $this->od($st, $p.'-01-02', 'Capital', [['521', 10000000, 0], ['101', 0, 10000000]]);
        $this->od($st, $p.'-06-30', 'Ventes N-1', [['521', 4000000, 0], ['701', 0, 4000000]]);
        $this->od($st, $y.'-06-30', 'Ventes N', [['521', 6000000, 0], ['701', 0, 6000000]]);
        $this->od($pt, $p.'-01-02', 'Capital', [['521', 50000000, 0], ['101', 0, 50000000]]);
        $this->od($pt, $p.'-01-02', 'Titres Konan Négoce (70 %)', [['261', 9000000, 0], ['521', 0, 9000000]]);
        $code = $this->api($st)->postJson('/api/compta/group/link-code')->json('data.code');
        // Écart d'acquisition 9 M − 70 % × 10 M = 2 M, amorti sur 5 ans
        $this->api($pt)->postJson('/api/compta/group/members', ['code' => $code, 'interest_pct' => 70, 'control_pct' => 70, 'method' => 'ig', 'investment_cost' => 9000000,
            'equity_at_acquisition' => 10000000, 'acquisition_date' => $p.'-01-01', 'goodwill_years' => 5])->assertOk()->assertJsonPath('data.goodwill_years', 5);
        $this->api($pt)->putJson('/api/compta/group/settings', ['deferred_tax_rate' => 25])->assertOk();
        // Fin N-1 : la mère détient 1 M de marchandises de la filiale (marge 30 %)
        $this->api($pt)->postJson('/api/compta/group/adjustments', ['to' => "$p-12-31", 'kind' => 'stock_margin', 'seller_id' => $sub->id, 'buyer_id' => $parent->id, 'amount' => 1000000, 'margin_pct' => 30])->assertOk();

        $days = fn (int $year) => (int) \Illuminate\Support\Carbon::create($year, 1, 1)->diffInDays(\Illuminate\Support\Carbon::create($year, 12, 31)) + 1;
        $amP = round(2000000 * $days($p) / (365.25 * 5), 2);
        $amN = round(2000000 * ($days($p) + $days($y)) / (365.25 * 5), 2) - $amP;

        $prev = $this->api($pt)->getJson("/api/compta/consolidation?from=$p-01-01&to=$p-12-31")->assertOk()->json('data');
        $this->assertEqualsWithDelta(4000000 - 300000 + 75000 - $amP, $prev['summary']['result'], 0.02);
        $this->assertEqualsWithDelta($amP, $prev['summary']['goodwill_charge'], 0.02);
        $this->assertTrue($prev['sheet']['balanced']);

        $s = $this->api($pt)->getJson("/api/compta/consolidation?from=$y-01-01&to=$y-12-31")->assertOk()->json('data');
        $sum = $s['summary'];
        // N : ventes 6 M − amortissement de l'année + reprise de la marge N-1 nette d'impôt (225 000)
        $this->assertEqualsWithDelta(6000000 - $amN + 225000, $sum['result'], 0.02);
        $this->assertEqualsWithDelta(1800000 + 67500, $sum['minority_result'], 0.02);
        $this->assertEqualsWithDelta(2000000 - $amP - $amN, $sum['goodwill'], 0.02);
        $this->assertTrue($s['sheet']['balanced'], json_encode([$s['sheet']['total_assets'], $s['sheet']['total_liabilities']]));
        $this->assertContains('stock_margin_reversal', array_column($s['adjustments'], 'kind'));
        // Flux : trésorerie d'ouverture = fin N-1 (mère 41 M + filiale 14 M), variation = 6 M encaissés
        $this->assertTrue($s['cashflow']['check']);
        $this->assertEquals([55000000, 6000000], [$s['cashflow']['opening'], $s['cashflow']['variation']]);
        // Variation des capitaux propres : ouverture = clôture N-1, clôture = capitaux propres part du groupe
        $rows = collect($s['equity_changes']['rows'])->keyBy('key');
        $this->assertEqualsWithDelta($prev['summary']['group_equity'], $rows['open']['group'], 0.05);
        $this->assertEqualsWithDelta($sum['group_equity'], $rows['close']['group'], 0.02);
        $this->assertEqualsWithDelta($sum['group_result'], $rows['result']['group'], 0.02);
        $this->assertStringStartsWith('%PDF', $this->api($pt)->get("/api/compta/consolidation/pdf?from=$y-01-01&to=$y-12-31")->getContent());
        $this->api($pt)->get("/api/compta/consolidation/xlsx?from=$y-01-01&to=$y-12-31")->assertOk();
    }

    public function test_stake_purchase_and_partial_sale_during_the_year(): void
    {
        [$parent, , $pt] = $this->makeCompany('Holding Yao');
        [$sub, , $st] = $this->makeCompany('Yao Distribution');
        $y = (int) now()->year - 1;
        $p = $y - 1;
        $this->api($pt)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $this->api($st)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $this->od($st, $p.'-01-02', 'Capital', [['521', 10000000, 0], ['101', 0, 10000000]]);
        $this->od($st, $y.'-03-31', 'Ventes S1', [['521', 6000000, 0], ['701', 0, 6000000]]);
        $this->od($st, $y.'-09-30', 'Ventes S2', [['521', 6000000, 0], ['701', 0, 6000000]]);
        $this->od($pt, $p.'-01-02', 'Capital', [['521', 50000000, 0], ['101', 0, 50000000]]);
        $this->od($pt, $p.'-01-02', 'Titres 60 %', [['261', 6000000, 0], ['521', 0, 6000000]]);
        $this->od($pt, $y.'-07-01', 'Achat de 20 % complémentaires', [['261', 3000000, 0], ['521', 0, 3000000]]);
        $this->od($pt, $y.'-10-01', 'Cession de 10 %', [['521', 2500000, 0], ['82', 0, 2500000]]);
        $this->od($pt, $y.'-10-01', 'Titres cédés', [['81', 1125000, 0], ['261', 0, 1125000]]);
        $code = $this->api($st)->postJson('/api/compta/group/link-code')->json('data.code');
        $m = $this->api($pt)->postJson('/api/compta/group/members', ['code' => $code, 'interest_pct' => 60, 'control_pct' => 60, 'method' => 'ig', 'investment_cost' => 6000000,
            'equity_at_acquisition' => 10000000, 'acquisition_date' => $p.'-01-01'])->json('data');
        $this->api($pt)->postJson("/api/compta/group/members/{$m['id']}/stakes", ['date' => "$y-07-01", 'change_pct' => 50, 'amount' => 1])->assertStatus(422); // 110 %
        $this->api($pt)->postJson("/api/compta/group/members/{$m['id']}/stakes", ['date' => "$y-07-01", 'change_pct' => 20, 'amount' => 3000000])->assertOk();
        $r = $this->api($pt)->postJson("/api/compta/group/members/{$m['id']}/stakes", ['date' => "$y-10-01", 'change_pct' => -10, 'amount' => 2500000])->assertOk()->json('data');
        $this->assertEquals([70, 7875000, 1125000], [$r['current_pct'], $r['current_cost'], $r['stakes'][1]['cost_basis']]);

        $s = $this->api($pt)->getJson("/api/compta/consolidation?from=$y-01-01&to=$y-12-31")->assertOk()->json('data');
        $sum = $s['summary'];
        // Résultat 12 M (le résultat de cession de 1,375 M chez la mère est une opération entre associés)
        // minoritaires : 40 % × 6 M (1er semestre) + 20 % × 6 M (3e trimestre)
        $this->assertEquals([12000000, 3600000, 8400000], [$sum['result'], $sum['minority_result'], $sum['group_result']]);
        // Minoritaires à la clôture : 30 % × (10 M + 12 M) = 6,6 M dont 3 M hors résultat
        $this->assertEquals(3000000, $sum['minority_interests']);
        // Capitaux propres du groupe : 50 M + 8,4 M + 0,2 M (20 % de 16 M achetés 3 M) + 0,3 M (10 % de 22 M vendus 2,5 M)
        $this->assertEquals(58900000, $sum['group_equity']);
        $this->assertTrue($s['sheet']['balanced'], json_encode([$s['sheet']['total_assets'], $s['sheet']['total_liabilities'], $s['adjustments']]));
        $this->assertEquals(70, collect($s['perimeter'])->firstWhere('name', 'Yao Distribution')['interest_pct']);
        $this->assertContains('stake_sale', array_column($s['adjustments'], 'kind'));
        $this->assertTrue($s['cashflow']['check']);
        $close = collect($s['equity_changes']['rows'])->firstWhere('key', 'close');
        $this->assertEquals([58900000, 6600000], [$close['group'], $close['minority']]);
    }

    public function test_subsidiary_leaving_the_group(): void
    {
        [$parent, , $pt] = $this->makeCompany('Holding Ouattara');
        [$sub, , $st] = $this->makeCompany('Ouattara Logistique');
        $y = (int) now()->year - 1;
        $p = $y - 1;
        $this->api($pt)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $this->api($st)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $this->od($st, $p.'-01-02', 'Capital', [['521', 10000000, 0], ['101', 0, 10000000]]);
        $this->od($st, $p.'-06-30', 'Ventes N-1', [['521', 4000000, 0], ['701', 0, 4000000]]);
        $this->od($st, $y.'-03-31', 'Ventes N avant cession', [['521', 2000000, 0], ['701', 0, 2000000]]);
        $this->od($st, $y.'-09-30', 'Ventes après la sortie', [['521', 3000000, 0], ['701', 0, 3000000]]);
        $this->od($pt, $p.'-01-02', 'Capital', [['521', 50000000, 0], ['101', 0, 50000000]]);
        $this->od($pt, $p.'-01-02', 'Titres 100 %', [['261', 10000000, 0], ['521', 0, 10000000]]);
        $this->od($pt, $y.'-06-30', 'Cession des titres', [['521', 18000000, 0], ['82', 0, 18000000]]);
        $this->od($pt, $y.'-06-30', 'Titres cédés', [['81', 10000000, 0], ['261', 0, 10000000]]);
        $code = $this->api($st)->postJson('/api/compta/group/link-code')->json('data.code');
        $m = $this->api($pt)->postJson('/api/compta/group/members', ['code' => $code, 'interest_pct' => 100, 'control_pct' => 100, 'method' => 'ig', 'investment_cost' => 10000000,
            'equity_at_acquisition' => 10000000, 'acquisition_date' => $p.'-01-01'])->json('data');
        $this->api($pt)->putJson("/api/compta/group/members/{$m['id']}", ['exit_date' => "$y-06-30", 'exit_proceeds' => 18000000])->assertOk()->assertJsonPath('data.exit_date', "$y-06-30");

        // N-1 : filiale intégrée normalement
        $prev = $this->api($pt)->getJson("/api/compta/consolidation?from=$p-01-01&to=$p-12-31")->json('data');
        $this->assertEquals(4000000, $prev['summary']['result']);
        // N : résultat de la filiale jusqu'à la sortie (2 M) + plus-value consolidée 18 M − 16 M (au lieu de 8 M chez la mère)
        $s = $this->api($pt)->getJson("/api/compta/consolidation?from=$y-01-01&to=$y-12-31")->assertOk()->json('data');
        $row = collect($s['perimeter'])->firstWhere('name', 'Ouattara Logistique');
        $this->assertEquals([2000000, 8000000, "$y-06-30"], [$row['exit_gain'], $row['exit_gain_parent'], $row['exit_date']]);
        $this->assertEquals(4000000, $s['summary']['result']);
        $this->assertEquals(58000000, $s['summary']['group_equity']);
        $this->assertTrue($s['sheet']['balanced'], json_encode([$s['sheet']['total_assets'], $s['sheet']['total_liabilities']]));
        // Flux : 54 M à l'ouverture (mère 40 M + filiale 14 M), 58 M à la clôture
        $this->assertTrue($s['cashflow']['check'], json_encode($s['cashflow']['rows']));
        $this->assertEquals([54000000, 4000000], [$s['cashflow']['opening'], $s['cashflow']['variation']]);
        $this->assertNotNull(collect($s['cashflow']['rows'])->firstWhere('key', 'FJ'));
        $cf = collect($s['cashflow']['rows'])->keyBy('key');
        $this->assertEquals([18000000, -16000000, 2000000], [$cf['FI']['amount'], $cf['FJ']['amount'], $cf['FA']['amount']]); // prix encaissé, trésorerie de la filiale sortie, CAFG hors plus-value
        $rows = collect($s['equity_changes']['rows'])->keyBy('key');
        $this->assertEquals([$prev['summary']['group_equity'], 58000000], [$rows['open']['group'], $rows['close']['group']]);
        // L'année suivante, la société n'est plus dans le périmètre
        $next = $this->api($pt)->getJson('/api/compta/consolidation?from='.($y + 1).'-01-01&to='.($y + 1).'-12-31')->json('data');
        $this->assertCount(1, $next['perimeter']);
    }
}
