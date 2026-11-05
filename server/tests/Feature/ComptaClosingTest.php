<?php

namespace Tests\Feature;

use App\Modules\Compta\Models\Entry;
use Tests\TestCase;

/** Fin d'exercice : contrôles, impôt (IS / IMF), clôture, réouverture, affectation, liasse DSF, état annexé de TVA. */
class ComptaClosingTest extends TestCase
{
    public function test_year_end_closing_tax_allocation_liasse_and_vat_annex(): void
    {
        [$company, , $token] = $this->makeCompany('Négoce Bamba');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $y = (int) now()->year - 1;
        $setup = $api()->getJson('/api/compta/setup')->assertOk()->json();
        $acc = collect($setup['accounts'])->keyBy('code');
        $od = collect($setup['journals'])->firstWhere('code', 'OD')['id'];

        // Capital de 10 000 000 apporté en banque, vente de 100 000 000 HT payée, achat de 40 000 000 HT
        $api()->postJson('/api/compta/entries', ['journal_id' => $od, 'date' => $y.'-01-02', 'label' => 'Apport en capital', 'lines' => [
            ['account_id' => $acc['521']['id'], 'debit' => 10000000, 'credit' => 0], ['account_id' => $acc['101']['id'], 'debit' => 0, 'credit' => 10000000]]])->assertCreated();
        $cust = $api()->postJson('/api/core/partners', ['name' => 'SOTRA', 'type' => 'customer', 'tax_id' => '9502345 K'])->json('data.id');
        $sup = $api()->postJson('/api/core/partners', ['name' => 'Grossiste Abobo', 'type' => 'supplier'])->json('data.id');
        $ncc = $api()->postJson('/api/core/partners', ['name' => 'CFAO Motors', 'type' => 'supplier', 'tax_id' => '0101234 A'])->json('data.id');
        $res = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $inv = $this->uuid(),
            'data' => ['type' => 'invoice', 'partner_id' => $cust, 'date' => $y.'-03-10', 'status' => 'validated', 'lines' => [['id' => $this->uuid(), 'description' => 'Fournitures', 'quantity' => 1, 'unit_price' => 100000000, 'tax_rate' => 18, 'discount_percent' => 0]]]]]])->json('results.0');
        $this->assertSame('applied', $res['status'], json_encode($res));
        $api()->postJson('/api/ventes/payments', ['document_id' => $inv, 'date' => $y.'-03-20', 'amount' => 118000000, 'method' => 'bank_transfer'])->assertCreated();
        $buy = function (string $partner, float $ht, string $date) use ($api) {
            $doc = $api()->postJson('/api/achats/documents', ['type' => 'supplier_invoice', 'partner_id' => $partner, 'date' => $date, 'reference' => 'F-'.random_int(100, 999),
                'lines' => [['description' => 'Marchandises', 'quantity' => 1, 'unit_price' => $ht, 'tax_rate' => 18]]])->assertCreated()->json('data');
            $api()->postJson("/api/achats/documents/{$doc['id']}/validate")->assertOk();

            return $doc['id'];
        };
        $buy($sup, 30000000, $y.'-03-15');
        $buy($ncc, 10000000, $y.'-03-25');

        // État annexé de TVA de mars : 18 000 000 collectés, 7 200 000 déductibles, un fournisseur sans NCC
        $vat = $api()->getJson('/api/compta/vat/return?from='.$y.'-03-01&to='.$y.'-03-31')->assertOk()->json('data');
        $this->assertEquals([18000000, 7200000, 10800000, 0], [$vat['collected'], $vat['deductible'], $vat['payable'], $vat['prior_credit']]);
        $this->assertCount(2, $vat['purchases']);
        $this->assertSame(1, $vat['missing_ncc']);
        $this->assertSame('9502345 K', $vat['sales'][0]['ncc']);
        $api()->get('/api/compta/vat/return/xlsx?from='.$y.'-03-01&to='.$y.'-03-31')->assertOk();
        $this->assertStringStartsWith('%PDF', $api()->get('/api/compta/vat/return/pdf?from='.$y.'-03-01&to='.$y.'-03-31')->getContent());

        // Une facture fournisseur en attente de vérification bloque la clôture
        $api()->putJson('/api/compta/settings', ['purchase_review' => true])->assertOk();
        $pending = $buy($sup, 1000, $y.'-12-20');
        $d = $api()->getJson("/api/compta/years/$y")->assertOk()->json('data');
        $checks = collect($d['checks'])->keyBy('key');
        $this->assertSame(['ok', 'ok', 'error'], [$checks['finished']['level'], $checks['previous']['level'], $checks['purchases']['level']]);
        $api()->postJson("/api/compta/years/$y/close")->assertStatus(422)->assertJsonFragment(['message' => 'Clôture impossible : 1 facture(s) fournisseur de l\'exercice attendent la comptabilité (à vérifier, approuvées ou refusées).']);
        $api()->postJson("/api/compta/purchases/$pending/decide", ['decision' => 'post'])->assertOk();

        // Impôt : résultat 59 999 000 + réintégration 4 001 000 = 64 000 000 → IS 25 % = 16 000 000 (supérieur à l'IMF de 3 000 000)
        $f = $api()->putJson("/api/compta/years/$y/fiscal", ['reintegrations' => [['label' => 'Amendes et pénalités', 'amount' => 4001000]], 'deductions' => []])->assertOk()->json('data');
        $this->assertEquals([59999000, 64000000, 16000000, 3000000, 16000000, 'is'], [$f['result_before_tax'], $f['taxable'], $f['is'], $f['minimum'], $f['due'], $f['basis']]);
        $this->assertSame('warning', collect($api()->getJson("/api/compta/years/$y")->json('data.checks'))->firstWhere('key', 'tax')['level']);
        $f = $api()->postJson("/api/compta/years/$y/tax")->assertOk()->json('data.fiscal');
        $this->assertEquals([16000000, 0, 43999000], [$f['posted'], $f['to_post'], $f['net_result']]);
        $api()->postJson("/api/compta/years/$y/tax")->assertOk(); // idempotent
        $this->assertSame(1, Entry::where('source_type', 'year_tax')->count());

        // Clôture : solde des comptes de gestion vers 131, période verrouillée ; le compte de résultat reste lisible
        $d = $api()->postJson("/api/compta/years/$y/close")->assertOk()->json('data');
        $this->assertSame('closed', $d['status']);
        $this->assertEquals(43999000, $d['result']);
        $this->assertSame($y.'-12-31', $d['lock_date']);
        $income = $api()->getJson("/api/compta/statements/income?from=$y-01-01&to=$y-12-31")->json('data');
        $this->assertEquals(43999000, $income['result']);
        $sheet = $api()->getJson("/api/compta/statements/sheet?to=$y-12-31")->json('data');
        $this->assertTrue($sheet['balanced'], json_encode($sheet));
        $this->assertEquals(43999000, collect($sheet['liabilities'])->firstWhere('ref', 'CJ')['n']);
        $this->assertTrue($api()->getJson("/api/compta/statements/cashflow?from=$y-01-01&to=$y-12-31")->json('data.check'));
        $closing = Entry::where('kind', 'closing')->first();
        $this->assertSame('CL', $closing->journal->code);
        $this->assertSame($y.'-12-31', $closing->date->toDateString());
        $api()->postJson("/api/compta/years/$y/close")->assertStatus(422);

        // Réouverture puis nouvelle clôture
        $api()->postJson("/api/compta/years/$y/reopen")->assertOk()->assertJsonPath('data.status', 'open');
        $this->assertSame(0, Entry::where('kind', 'closing')->count());
        $api()->postJson("/api/compta/years/$y/close")->assertOk();

        // Affectation : réserve légale 10 % plafonnée à 20 % du capital (2 000 000), dividendes, report à nouveau
        $p = $api()->getJson("/api/compta/years/$y")->json('data.allocation');
        $this->assertEquals([10000000, 2000000, 41999000], [$p['capital'], $p['proposal']['legal_reserve'], $p['proposal']['report']]);
        $api()->postJson("/api/compta/years/$y/allocate", ['legal_reserve' => 2000000, 'dividends' => 10000000, 'report' => 30000000, 'date' => ($y + 1).'-04-30'])->assertStatus(422);
        $api()->postJson("/api/compta/years/$y/allocate", ['legal_reserve' => 2000000, 'dividends' => 10000000, 'report' => 31999000, 'date' => $y.'-12-31'])->assertStatus(422);
        $api()->postJson("/api/compta/years/$y/allocate", ['legal_reserve' => 2000000, 'dividends' => 10000000, 'report' => 31999000, 'date' => ($y + 1).'-04-30'])->assertOk();
        $resp = $api()->getJson("/api/compta/balance?from=".($y + 1)."-01-01&to=".($y + 1)."-12-31")->json("data");
        $bal = collect($resp["rows"])->keyBy("code");
        $this->assertEquals([-2000000, -10000000, -31999000], [$bal['111']['balance'], $bal['465']['balance'], $bal['121']['balance']]);
        $api()->postJson("/api/compta/years/$y/reopen")->assertStatus(422);

        // Liasse (DSF) : identification, états, notes, résultat fiscal, balance ; Excel et PDF
        $l = $api()->getJson("/api/compta/years/$y/liasse")->assertOk()->json('data');
        $this->assertStringContainsString('DSF', $l['title']);
        $this->assertSame('closed', $l['status']);
        $this->assertEquals(100000000, array_sum(array_column($l['revenue_months'], 'amount')));
        $this->assertEquals(16000000, $l['fiscal']['due']);
        $this->assertNotEmpty($l['balance']);
        $api()->get("/api/compta/years/$y/liasse/xlsx")->assertOk();
        $this->assertStringStartsWith('%PDF', $api()->get("/api/compta/years/$y/liasse/pdf")->getContent());
        $years = $api()->getJson('/api/compta/years')->assertOk()->json('data');
        $this->assertSame('closed', collect($years)->firstWhere('year', $y)['status']);
    }

    public function test_shifted_fiscal_year_july_to_june(): void
    {
        [, , $token] = $this->makeCompany('Coopérative Cacao');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $y = (int) now()->year - 1; // exercice du 01/07/$y au 30/06/($y+1)
        $this->assertTrue(now()->toDateString() > ($y + 1).'-06-30');
        $api()->putJson('/api/compta/settings', ['fiscal_start_month' => 7])->assertOk()->assertJsonPath('fiscal_start_month', 7);
        $cust = $api()->postJson('/api/core/partners', ['name' => 'Exportateur', 'type' => 'customer'])->json('data.id');
        $sell = function (string $date, float $ht) use ($api, $cust) {
            $r = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(),
                'data' => ['type' => 'invoice', 'partner_id' => $cust, 'date' => $date, 'status' => 'validated', 'lines' => [['id' => $this->uuid(), 'description' => 'Fèves', 'quantity' => 1, 'unit_price' => $ht, 'tax_rate' => 0, 'discount_percent' => 0]]]]]])->json('results.0');
            $this->assertSame('applied', $r['status'], json_encode($r));
        };
        $sell($y.'-06-15', 1000000);        // exercice précédent
        $sell($y.'-08-15', 2000000);        // exercice $y
        $sell(($y + 1).'-03-10', 3000000);  // exercice $y
        $years = collect($api()->getJson('/api/compta/years')->json('data'))->keyBy('year');
        $this->assertSame([$y.'-'.($y + 1), $y.'-07-01', ($y + 1).'-06-30'], [$years[$y]['label'], $years[$y]['start'], $years[$y]['end']]);
        $this->assertEquals(5000000, $years[$y]["result"]);
        // L'exercice précédent (avec écritures) doit être clôturé d'abord
        $api()->postJson('/api/compta/years/'.($y - 1).'/close')->assertOk();
        $d = $api()->postJson("/api/compta/years/$y/close")->assertOk()->json('data');
        $this->assertEquals([5000000, ($y + 1).'-06-30'], [$d['result'], $d['lock_date']]);
        $this->assertEquals(5000000, $api()->getJson("/api/compta/statements/income?from=$y-07-01&to=".($y + 1).'-06-30')->json('data.result'));
        $sheet = $api()->getJson('/api/compta/statements/sheet?to='.($y + 1).'-06-30')->json('data');
        $this->assertTrue($sheet['balanced']);
        $this->assertSame($y.'-06-30', $sheet['prev_at']);
        $this->assertEquals(5000000, collect($sheet['liabilities'])->firstWhere('ref', 'CJ')['n']);
        $this->assertSame(['juillet', 'juin'], [$this->monthName($api()->getJson("/api/compta/years/$y/liasse")->json('data.revenue_months.0.month')), $this->monthName($api()->getJson("/api/compta/years/$y/liasse")->json('data.revenue_months.11.month'))]);
        $api()->putJson('/api/compta/settings', ['fiscal_start_month' => 1])->assertStatus(422);
    }

    private function monthName(int $m): string
    {
        return ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'][$m - 1];
    }
}
