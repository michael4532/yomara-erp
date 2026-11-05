<?php

namespace Tests\Feature;

use App\Modules\Paie\CountryPayrollCalculator;
use App\Modules\Paie\PaieSettings;
use App\Modules\Paie\PayrollCalculator;
use App\Modules\Paie\PayrollCountries;
use Tests\TestCase;

class PaieMultiCountryTest extends TestCase
{
    public function test_generic_engine_reproduces_ivorian_payroll(): void
    {
        $ci = new PayrollCalculator(PaieSettings::DEFAULTS);
        $g = new CountryPayrollCalculator(PayrollCountries::builtIn()['CI']);
        foreach ([[75000, 1, 0, 0, false], [300000, 2, 2, 30000, false], [900000, 3, 4, 50000, false], [5000000, 1.5, 1, 0, false], [250000, 1, 0, 25000, true]] as [$base, $parts, $kids, $transport, $expat]) {
            $in = ['base_salary' => $base, 'parts' => $parts, 'children_count' => $kids, 'transport' => $transport, 'seniority_years' => 5, 'is_expatriate' => $expat,
                'items' => [['label' => 'Prime', 'amount' => 20000], ['label' => 'Avance', 'amount' => 10000, 'kind' => 'deduction']]];
            $a = $ci->compute($in)['totals'];
            $b = $g->compute($in)['totals'];
            foreach (['gross', 'net', 'cost', 'its', 'employer_social', 'employer_tax'] as $k) {
                $this->assertSame($a[$k], $b[$k], "$k pour $base");
            }
        }
    }

    public function test_all_country_profiles_are_valid_and_consistent(): void
    {
        $codes = array_keys(PayrollCountries::builtIn());
        $this->assertEqualsCanonicalizing(['CI', 'SN', 'BF', 'ML', 'TG', 'BJ', 'NE', 'GW', 'CM', 'GA', 'CG', 'TD', 'CF', 'GQ'], $codes);
        foreach (PayrollCountries::builtIn() as $code => $p) {
            $this->assertNull(PayrollCountries::validate($p), $code);
            $this->assertContains($p['currency'], ['XOF', 'XAF'], $code);
            $this->assertNotEmpty($p['sources'], $code);
            $calc = new CountryPayrollCalculator($p);
            $prev = 0;
            foreach ([100000, 400000, 1500000] as $base) {
                $t = $calc->compute(['base_salary' => $base, 'marital_status' => 'married', 'children_count' => 2, 'month' => 6])['totals'];
                $this->assertGreaterThan(0, $t['net'], $code);
                $this->assertLessThan($t['gross'], $t['net'], $code);
                $this->assertGreaterThan($t['gross'], $t['cost'], $code);
                $this->assertGreaterThan($prev, $t['net'], "$code : le net croît avec le brut");
                $prev = $t['net'];
            }
        }
    }

    public function test_cameroon_irpp_and_local_taxes(): void
    {
        $r = (new CountryPayrollCalculator(PayrollCountries::builtIn()['CM']))->compute(['base_salary' => 150000]);
        $t = $r['totals'];
        $this->assertSame(6300.0, $t['cnps_salarie']);                  // 4,2 % retraite
        // (150 000 × 12 × 70 % − 6 300 × 12 − 500 000) × 10 % / 12
        $this->assertSame(round((1800000 * 0.7 - 75600 - 500000) * 0.10 / 12), $t['income_tax']);
        $codes = array_column($r['withholdings'], 'amount', 'code');
        $this->assertSame(570.0, $codes['cac']);                         // 10 % de l'IRPP
        $this->assertSame(1500.0, $codes['cfc']);
        $this->assertSame(1950.0, $codes['rav']);
        $this->assertSame(1000.0, $codes['tdl']);
        // Salaire exonéré d'IRPP jusqu'à 62 000 F
        $this->assertSame(0.0, (new CountryPayrollCalculator(PayrollCountries::builtIn()['CM']))->compute(['base_salary' => 60000])['totals']['income_tax']);
    }

    public function test_family_rules_quotient_and_reductions(): void
    {
        // Gabon : quotient familial — le marié avec enfants paie moins
        $ga = new CountryPayrollCalculator(PayrollCountries::builtIn()['GA']);
        $single = $ga->compute(['base_salary' => 1000000])['totals']['income_tax'];
        $family = $ga->compute(['base_salary' => 1000000, 'marital_status' => 'married', 'children_count' => 2])['totals']['income_tax'];
        $this->assertGreaterThan($family, $single);
        // Mali : marié 10 % + 2,5 % par enfant
        $ml = new CountryPayrollCalculator(PayrollCountries::builtIn()['ML']);
        $r = $ml->compute(['base_salary' => 500000, 'marital_status' => 'married', 'children_count' => 2]);
        $its = collect($r['withholdings'])->firstWhere('code', 'its');
        $this->assertEqualsWithDelta($its['detail']['brut'] * 0.15, $its['detail']['reduction'], 1);
        // Bénin : redevance ORTB seulement en mars et juin
        $bj = new CountryPayrollCalculator(PayrollCountries::builtIn()['BJ']);
        $this->assertSame(0.0, $bj->compute(['base_salary' => 300000, 'month' => 5])['totals']['other_taxes']);
        $this->assertSame(3000.0, $bj->compute(['base_salary' => 300000, 'month' => 6])['totals']['other_taxes']);
        // Non déclaré : aucun prélèvement
        $t = $bj->compute(['base_salary' => 300000, 'declared' => false])['totals'];
        $this->assertSame([300000.0, 300000.0], [$t['net'], $t['cost']]);
    }

    public function test_company_switches_country_customizes_and_pays(): void
    {
        [$company, , $token] = $this->makeCompany('Dakar Digital');
        $api = fn () => $this->api($token);
        $s = $api()->getJson('/api/paie/settings')->assertOk()->json();
        $this->assertSame(['CI', 'ci'], [$s['country'], $s['engine']]);
        $this->assertContains('SN', array_column($s['countries'], 'code'));

        // Passage au Sénégal
        $s = $api()->putJson('/api/paie/profile', ['country' => 'SN'])->assertOk()->json();
        $this->assertSame(['SN', 'profile', 'IR'], [$s['country'], $s['engine'], $s['profile']['income_tax']['label']]);
        $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'rh_employees', 'record_id' => $this->uuid(),
            'data' => ['first_name' => 'Awa', 'last_name' => 'Ndiaye', 'base_salary' => 500000, 'marital_status' => 'married', 'children_count' => 2, 'hire_date' => '2020-01-01']]]])->assertOk();
        $prev = $api()->postJson('/api/paie/quick/preview', ['period' => now()->format('Y-m')])->assertOk()->json();
        $slip = \App\Modules\Paie\Models\Payslip::find($prev['payslips'][0]['id']);
        $codes = array_column($slip->result['withholdings'], 'code');
        $this->assertContains('ir', $codes);
        $this->assertContains('ipres_rg', $codes);
        $this->assertSame('SN', $slip->result['country']);

        // Déclaration multi-pays par blocs
        $d = $api()->getJson('/api/paie/runs/'.$prev['run']['id'].'/declaration')->assertOk()->json();
        $this->assertSame(['social', 'tax', 'employer_tax'], array_column($d['blocks'], 'key'));
        $this->assertSame(1, $d['headcount']);
        $this->assertContains('CFCE', array_map(fn ($r) => explode(' ', $r['label'])[0], $d['blocks'][2]['rows']));

        // Profil modifié par la société (IPM à 0) puis rétabli
        $p = $s['profile'];
        $p['contributions'] = array_values(array_filter($p['contributions'], fn ($c) => $c['code'] !== 'ipm'));
        $s = $api()->putJson('/api/paie/profile', ['country' => 'SN', 'profile' => $p])->assertOk()->json();
        $this->assertTrue($s['profile']['customized']);
        $this->assertNotContains('ipm', array_column($s['profile']['contributions'], 'code'));
        $bad = $p;
        $bad['income_tax']['brackets'] = [['up_to' => null, 'rate' => 10], ['up_to' => 100, 'rate' => 20]];
        $api()->putJson('/api/paie/profile', ['country' => 'SN', 'profile' => $bad])->assertStatus(422);
        $s = $api()->deleteJson('/api/paie/profile/SN')->assertOk()->json();
        $this->assertFalse($s['profile']['customized']);

        // Profil libre
        $s = $api()->putJson('/api/paie/profile', ['country' => 'XX'])->assertOk()->json();
        $this->assertSame('XX', $s['profile']['code']);
        $api()->putJson('/api/paie/profile', ['country' => 'ZZ'])->assertStatus(422);
    }
}
