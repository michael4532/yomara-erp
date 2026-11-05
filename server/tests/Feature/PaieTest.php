<?php

namespace Tests\Feature;

use App\Modules\Paie\PaieSettings;
use App\Modules\Paie\PayrollCalculator;
use Tests\TestCase;

class PaieTest extends TestCase
{
    protected function calc(array $override = []): PayrollCalculator
    {
        return new PayrollCalculator(array_replace(PaieSettings::DEFAULTS, $override));
    }

    public function test_calculator_matches_reference_example(): void
    {
        // Salarié marié, 2 enfants (3 parts), brut 500 000 F, conjoint qui travaille
        $r = $this->calc()->compute(['base_salary' => 500000, 'parts' => 3, 'children_count' => 2, 'spouse_dependent' => false]);
        $t = $r['totals'];

        $this->assertSame(500000.0, $t['gross']);
        $this->assertSame(81000.0, $t['its_brut']);   // 26 400 + 54 600
        $this->assertSame(22000.0, $t['ricf']);       // 4 demi-parts × 5 500
        $this->assertSame(59000.0, $t['its']);
        $this->assertSame(31500.0, $t['cnps_salarie']); // 6,3 %
        $this->assertSame(1500.0, $t['cmu_salarie']);   // salarié + 2 enfants × 500
        $this->assertSame(500000.0 - 31500 - 1500 - 59000, $t['net']);

        // Charges patronales
        $this->assertSame(38500.0, $t['retraite_employeur']); // 7,7 %
        $this->assertSame(3500.0, $t['pf']);                  // 5 % × 70 000 (plafond)
        $this->assertSame(6000.0, $t['contribution']);        // 1,2 %
        $this->assertSame(2000.0 + 6000.0, $t['fdfp_ta'] + $t['fdfp_fpc']);
        $this->assertSame($t['gross'] + $t['employer_total'], $t['cost']);
    }

    public function test_smig_is_not_taxed_and_ricf_never_negative(): void
    {
        $t = $this->calc()->compute(['base_salary' => 75000, 'parts' => 5])['totals'];
        $this->assertSame(0.0, $t['its']);
        $this->assertSame(4725.0, $t['cnps_salarie']);

        // Petit salaire avec beaucoup de parts : réduction plafonnée à l'impôt
        $t = $this->calc()->compute(['base_salary' => 120000, 'parts' => 5])['totals'];
        $this->assertSame(7200.0, $t['its_brut']);
        $this->assertSame(0.0, $t['its']);
    }

    public function test_prorata_transport_seniority_ceiling_and_expatriate(): void
    {
        // 15 jours sur 30, transport 40 000 dont 30 000 exonérés
        $r = $this->calc()->compute(['base_salary' => 300000, 'days_worked' => 15, 'transport' => 40000]);
        $this->assertSame(150000.0, $r['gains'][0]['amount']);
        $this->assertSame(20000.0, $r['totals']['gross'] - 150000.0); // transport proratisé, sous le plafond
        $this->assertSame(150000.0, $r['totals']['taxable']);

        $r = $this->calc()->compute(['base_salary' => 300000, 'transport' => 40000]);
        $this->assertSame(310000.0, $r['totals']['taxable']); // 10 000 de transport imposable

        // Ancienneté : 5 ans → 5 %
        $r = $this->calc()->compute(['base_salary' => 200000, 'seniority_years' => 5]);
        $this->assertSame(210000.0, $r['totals']['gross']);

        // Plafond retraite et contribution expatrié
        $t = $this->calc()->compute(['base_salary' => 5000000, 'is_expatriate' => true])['totals'];
        $this->assertSame(3375000.0, $t['base_retraite']);
        $this->assertSame(round(3375000 * 0.063), $t['cnps_salarie']);
        $this->assertSame(520000.0, $t['contribution']); // 10,4 %

        // Retenue (avance) : déduite du net, sans effet sur l'impôt
        $a = $this->calc()->compute(['base_salary' => 300000])['totals'];
        $b = $this->calc()->compute(['base_salary' => 300000, 'items' => [['label' => 'Avance', 'amount' => 50000, 'kind' => 'deduction']]])['totals'];
        $this->assertSame($a['its'], $b['its']);
        $this->assertSame($a['net'] - 50000, $b['net']);
    }

    public function test_full_payroll_cycle_with_accounting(): void
    {
        [$company, , $token] = $this->makeCompany('Paie SARL');
        $period = now()->format('Y-m');
        $start = now()->startOfMonth();

        $kone = $this->api($token)->postJson('/api/rh/employees', [
            'first_name' => 'Awa', 'last_name' => 'Koné', 'base_salary' => 500000, 'marital_status' => 'married', 'children_count' => 2,
            'hire_date' => '2020-01-01', 'transport_allowance' => 30000, 'cnps_number' => '1234567',
            'fixed_items' => [['label' => 'Prime de responsabilité', 'amount' => 50000]],
        ])->assertCreated()->json();
        $this->assertSame('EMP00001', $kone['matricule']);
        $this->assertEquals(3, $kone['tax_parts']);

        // Embauché le 16 du mois → 15 jours (ou moins selon le mois)
        $new = $this->api($token)->postJson('/api/rh/employees', [
            'first_name' => 'Yao', 'last_name' => 'Kouassi', 'base_salary' => 150000, 'hire_date' => $start->copy()->addDays(15)->toDateString(),
        ])->assertCreated()->json();

        // Absence non payée de 2 jours pour Koné
        $leave = $this->api($token)->postJson('/api/rh/leaves', [
            'employee_id' => $kone['id'], 'type' => 'unpaid', 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDay()->toDateString(), 'approve' => true,
        ])->assertCreated()->json();
        $this->assertSame('approved', $leave['status']);

        $res = $this->api($token)->postJson('/api/paie/runs', ['period' => $period])->assertCreated()->json();
        $this->assertCount(2, $res['payslips']);
        $slips = collect($res['payslips'])->keyBy('employee_id');
        $this->assertEquals(28, $slips[$kone['id']]['days_worked']);
        $this->assertEquals(min(30, $start->daysInMonth - 15), $slips[$new['id']]['days_worked']);
        $this->assertEquals(6, $slips[$kone['id']]['employee']['seniority_years']);

        // Saisie : 30 jours, heures supplémentaires et avance
        $slip = $this->api($token)->putJson('/api/paie/payslips/'.$slips[$kone['id']]['id'], [
            'days_worked' => 30,
            'items' => [
                ['label' => 'Prime de responsabilité', 'amount' => 50000, 'kind' => 'gain'],
                ['label' => 'Heures supplémentaires', 'amount' => 20000, 'kind' => 'gain'],
                ['label' => 'Avance sur salaire', 'amount' => 40000, 'kind' => 'deduction'],
            ],
        ])->assertOk()->json();
        // 500 000 + ancienneté 6 % 30 000 + 50 000 + 20 000 + transport 30 000
        $this->assertEquals(630000, $slip['gross']);
        $this->assertEquals(600000, $slip['result']['totals']['taxable']);

        $run = $this->api($token)->postJson('/api/paie/runs/'.$res['run']['id'].'/validate')->assertOk()->json('run');
        $this->assertSame('validated', $run['status']);
        $this->api($token)->putJson('/api/paie/payslips/'.$slip['id'], ['days_worked' => 20])->assertStatus(422);

        // Écriture SA équilibrée
        $balance = $this->api($token)->getJson('/api/compta/balance?from='.$start->toDateString().'&to='.now()->endOfMonth()->toDateString())->assertOk()->json();
        $acc = collect($balance['data']['rows'])->keyBy('code');
        $t = $run['totals'];
        $this->assertEquals($t['gross'], $acc['661']['debit']);
        $this->assertEquals($t['net'], $acc['422']['credit']);
        $this->assertEquals($t['its'], $acc['447']['credit']);
        $this->assertEquals(40000, $acc['421']['credit']);
        $this->assertEquals($t['employer_tax'], $acc['641']['debit']);

        // Déclaration
        $decl = $this->api($token)->getJson('/api/paie/runs/'.$run['id'].'/declaration')->assertOk()->json();
        $this->assertEquals($t['its'] + $t['contribution'], $decl['dgi']['total']);
        $this->assertCount(2, $decl['cnps']['employees']);

        // Livre de paie CSV
        $csv = $this->api($token)->get('/api/paie/runs/'.$run['id'].'/export')->assertOk()->getContent();
        $this->assertStringContainsString('Koné Awa', $csv);

        // Paiement par virement : 422 soldé
        $this->api($token)->postJson('/api/paie/runs/'.$run['id'].'/pay', ['method' => 'bank_transfer', 'date' => now()->toDateString()])->assertOk();
        $balance = $this->api($token)->getJson('/api/compta/balance?from='.$start->toDateString().'&to='.now()->endOfMonth()->toDateString())->json();
        $acc = collect($balance['data']['rows'])->keyBy('code');
        $this->assertEquals($acc['422']['debit'], $acc['422']['credit']);
        $this->api($token)->postJson('/api/paie/runs/'.$run['id'].'/reopen')->assertStatus(422);
        $this->api($token)->postJson('/api/paie/runs', ['period' => $period])->assertStatus(422);
    }

    public function test_reopen_removes_entry_and_simulator_net_to_gross(): void
    {
        [, , $token] = $this->makeCompany('Simu SARL');
        $this->api($token)->postJson('/api/rh/employees', ['first_name' => 'A', 'last_name' => 'B', 'base_salary' => 200000])->assertCreated();
        $run = $this->api($token)->postJson('/api/paie/runs', ['period' => now()->format('Y-m')])->json('run');
        $this->api($token)->postJson('/api/paie/runs/'.$run['id'].'/validate')->assertOk();
        $this->api($token)->postJson('/api/paie/runs/'.$run['id'].'/reopen')->assertOk()->assertJsonPath('run.status', 'draft');
        $bal = $this->api($token)->getJson('/api/compta/balance?to='.now()->endOfMonth()->toDateString())->json();
        $acc = collect($bal['data']['rows'])->keyBy('code');
        $this->assertFalse(isset($acc['661']) && $acc['661']['debit'] > 0);
        $this->api($token)->deleteJson('/api/paie/runs/'.$run['id'])->assertOk();

        $sim = $this->api($token)->postJson('/api/paie/simulate', ['target_net' => 408000, 'marital_status' => 'married', 'children_count' => 2])->assertOk()->json();
        $this->assertEqualsWithDelta(408000, $sim['totals']['net'], 1);
        $this->assertEqualsWithDelta(500000, $sim['base_salary'], 2);

        // Paramètres modifiables par société
        $this->api($token)->putJson('/api/paie/settings', ['accident_travail' => 3])->assertOk()->assertJsonPath('settings.accident_travail', 3);
    }
}
