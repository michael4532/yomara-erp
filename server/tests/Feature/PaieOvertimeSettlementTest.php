<?php

namespace Tests\Feature;

use App\Modules\Rh\DocRenderer;
use App\Modules\Rh\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PaieOvertimeSettlementTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_night_and_sunday_overtime_flow_into_payroll_and_final_settlement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-25 10:00:00'));
        [$company, , $token] = $this->makeCompany('Gardiennage Akwaba');
        $api = fn () => $this->api($token);
        $push = fn (array $mut) => $api()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid(), 'op' => 'upsert'], $mut)])->assertOk()->json('results');
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        [$awa, $kofi] = [$this->uuid(), $this->uuid()];
        $push([
            ['entity' => 'rh_employees', 'record_id' => $awa, 'data' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'base_salary' => 173333, 'hire_date' => '2025-01-01']],
            ['entity' => 'rh_employees', 'record_id' => $kofi, 'data' => ['first_name' => 'Kofi', 'last_name' => 'Yao', 'base_salary' => 300000, 'hire_date' => '2020-01-01']],
        ]);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk(); // badges créés

        // Semaine du 15 juin : 4 jours de 8 h, vendredi jusqu'à 23 h (2 h de nuit), dimanche 20 h – 23 h
        $punches = [];
        foreach (['15', '16', '17', '18'] as $d) {
            $punches[] = ["2026-06-$d 08:00", 'in'];
            $punches[] = ["2026-06-$d 17:00", 'out'];
        }
        array_push($punches, ['2026-06-19 08:00', 'in'], ['2026-06-19 23:00', 'out'], ['2026-06-21 20:00', 'in'], ['2026-06-21 23:00', 'out']);
        $r = $push(array_map(fn ($p) => ['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $awa, 'at' => $p[0], 'kind' => $p[1], 'source' => 'manual', 'note' => 'Saisie de la feuille de garde']], $punches));
        $this->assertSame(array_fill(0, count($punches), 'applied'), array_column($r, 'status'), json_encode($r[0]));
        $t = collect($api()->getJson('/api/pointage/report?from=2026-06-15&to=2026-06-21')->assertOk()->json('data.employees'))->firstWhere('employee_id', $awa)['totals'];
        // 46 h en semaine : 6 h sup. dont 2 h de nuit ; dimanche 3 h dont 2 h de nuit
        $this->assertSame([240, 0, 120, 60, 120], [$t['ot15'], $t['ot50'], $t['ot_night'], $t['ot75'], $t['ot100']]);

        // Paie de juin : heures supplémentaires reprises sans action (taux horaire 173 333 ÷ 173,33 h ≈ 1 000 F)
        $run = $api()->postJson('/api/paie/runs', ['period' => '2026-06'])->assertCreated()->json('run');
        $slip = collect($api()->getJson("/api/paie/runs/{$run['id']}")->json('payslips'))->firstWhere('employee_id', $awa);
        $ot = collect($slip['items'])->where('source', 'pointage')->keyBy('code');
        $this->assertEquals([4600, 3500, 1750, 4000], [$ot['ot15']['amount'], $ot['ot_night']['amount'], $ot['ot75']['amount'], $ot['ot100']['amount']]);
        $this->assertStringContainsString('de nuit +75 %', $ot['ot_night']['label']);
        // Bulletin modifié dans l'application (lignes renvoyées telles quelles, avec ou sans origine) puis recalculé : heures sup. jamais payées deux fois
        $sent = array_merge(array_map(fn ($i) => array_intersect_key($i, array_flip(['label', 'amount', 'kind', 'taxable', 'social'])), $slip['items']), [['label' => 'Prime', 'amount' => 1000, 'kind' => 'gain']]);
        $api()->putJson("/api/paie/payslips/{$slip['id']}", ['items' => $sent])->assertOk();
        $api()->postJson("/api/paie/runs/{$run['id']}/refresh")->assertOk();
        $after = collect($api()->getJson("/api/paie/runs/{$run['id']}")->json('payslips'))->firstWhere('employee_id', $awa);
        $this->assertSame(4, collect($after['items'])->whereIn('label', $ot->pluck('label')->all())->count());
        $this->assertSame(1, collect($after['items'])->where('label', 'Prime')->count());
        $this->assertEquals($slip['gross'] + 1000, $after['gross']);
        // Majorations modifiables
        $api()->putJson('/api/pointage/settings', ['overtime_rates' => ['ot_night' => 100]])->assertOk()->assertJsonPath('data.overtime_rates.ot_night', 100);

        // Solde de tout compte : licenciement de Kofi (6 ans 6 mois), préavis non effectué
        $prev = $api()->postJson("/api/rh/employees/$kofi/settlement", ['end_date' => '2026-06-30', 'reason' => 'dismissal'])->assertOk()->json();
        $lines = collect($prev['lines'])->keyBy('code');
        $this->assertSame([300000.0, 13.2, 'employe', 78], [(float) $prev['average_gross'], (float) $prev['leave_days'], $prev['notice_group'], $prev['seniority_months']]);
        $this->assertEquals(round(300000 / 26 * 13.2), $lines['conges']['amount']);
        $this->assertEquals(300000, $lines['preavis']['amount']);
        $this->assertEquals(round(300000 * (5 * 0.30 + 1.5 * 0.35)), $lines['licenciement']['amount']); // 5 ans à 30 % + 1,5 an à 35 %
        $this->assertFalse($lines['licenciement']['taxable']);
        // Démission : ni préavis ni indemnité de licenciement
        $dem = $api()->postJson("/api/rh/employees/$kofi/settlement", ['end_date' => '2026-06-30', 'reason' => 'resignation'])->json('lines');
        $this->assertSame(['conges'], array_column($dem, 'code'));

        // Validation : la paie de juin en brouillon reprend les lignes ; la fiche porte la date de sortie
        $applied = $api()->postJson("/api/rh/employees/$kofi/settlement/apply", ['end_date' => '2026-06-30', 'reason' => 'dismissal'])->assertOk()->json();
        $this->assertSame('2026-06-30', substr(DB::table('rh_employees')->where('id', $kofi)->value('end_date'), 0, 10));
        $slip = collect($api()->getJson("/api/paie/runs/{$run['id']}")->json('payslips'))->firstWhere('employee_id', $kofi);
        $this->assertEqualsCanonicalizing(['conges', 'preavis', 'licenciement'], collect($slip['items'])->where('source', 'stc')->pluck('code')->all());
        $this->assertGreaterThan(300000 + $applied['total'] * 0.8, (float) $slip['net']); // indemnité de licenciement non imposable
        $fields = DocRenderer::fields($company, Employee::find($kofi));
        $this->assertSame('Licenciement', $fields['motif_sortie']);
        $this->assertStringContainsString(number_format($applied['total'], 0, ',', ' '), str_replace(["\u{202f}", "\u{a0}"], ' ', $fields['montant_stc']));

        // Annulation : les lignes disparaissent de la paie
        $api()->deleteJson("/api/rh/employees/$kofi/settlement")->assertOk();
        $slip = collect($api()->getJson("/api/paie/runs/{$run['id']}")->json('payslips'))->firstWhere('employee_id', $kofi);
        $this->assertCount(0, collect($slip['items'])->where('source', 'stc'));
    }
}
