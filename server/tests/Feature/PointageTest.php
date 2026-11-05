<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Tests\TestCase;

class PointageTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_kiosk_self_and_manual_punches_presence_report_overtime_and_payroll(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 18:00:00')); // mercredi
        [$company, , $token] = $this->makeCompany('Supérette Bonheur');
        $api = fn () => $this->api($token);
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $roleName) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@bonheur.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        [$kofi, $kofiApi] = $mk('Kofi', 'Salarié');
        [, $kioskApi] = $mk('Borne', 'Borne de pointage');
        $push = fn ($client, array $mut) => $client()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid(), 'op' => 'upsert'], $mut)])->assertOk()->json('results');
        $pull = fn ($client) => $client()->getJson('/api/sync/pull?cursor=0')->assertOk()->json('changes');

        // Mise en place : horaire par défaut, badges créés avec les fiches
        $pull($api);
        $sched = collect($pull($api)['pt_schedules']['upserts'])->firstWhere('is_default', true);
        $this->assertSame(['start' => '08:00', 'end' => '17:00', 'break' => 60], $sched['days']['1']);
        $this->assertNull($sched['days']['6']);
        $eAwa = $this->uuid();
        $eKofi = $this->uuid();
        $push($api, [
            ['entity' => 'rh_employees', 'record_id' => $eAwa, 'data' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'base_salary' => 150000, 'hire_date' => '2025-01-01', 'department' => 'Boutique']],
            ['entity' => 'rh_employees', 'record_id' => $eKofi, 'data' => ['first_name' => 'Kofi', 'last_name' => 'Yao', 'base_salary' => 120000, 'hire_date' => '2025-01-01', 'user_id' => $kofi->id]],
        ]);
        $badges = collect($pull($api)['pt_badges']['upserts'])->keyBy('id');
        $this->assertSame('Koné Awa', $badges[$eAwa]['name']);
        $this->assertSame($sched['id'], $badges[$eAwa]['schedule_id']);
        $this->assertSame($kofi->id, $badges[$eKofi]['user_id']);

        // Codes PIN (haché, reçu par la borne)
        $api()->putJson("/api/pointage/badges/{$eAwa}/pin", ['pin' => '12'])->assertStatus(422);
        $api()->putJson("/api/pointage/badges/{$eAwa}/pin", ['pin' => '4821'])->assertOk();
        $kofiApi()->putJson('/api/pointage/me/pin', ['pin' => '7777'])->assertOk();
        $kb = collect($pull($kioskApi)['pt_badges']['upserts'])->keyBy('id');
        $this->assertSame(hash('sha256', $eAwa.':4821'), $kb[$eAwa]['pin_hash']);
        $this->assertSame(hash('sha256', $eKofi.':7777'), $kb[$eKofi]['pin_hash']);
        // Le salarié ne reçoit que son badge
        $this->assertSame([$eKofi], array_column($pull($kofiApi)['pt_badges']['upserts'], 'id'));

        // Semaine du 8 au 14 juin pointée à la borne pour Awa
        $week = [
            ['2026-06-08 08:05', 'in'], ['2026-06-08 17:00', 'out'],                                   // lun. : 7 h 55 − 1 h de pause
            ['2026-06-09 08:25', 'in'], ['2026-06-09 18:30', 'out'],                                   // mar. : retard 25 min
            // mer. : absente
            ['2026-06-11 07:55', 'in'], ['2026-06-11 12:00', 'out'], ['2026-06-11 13:00', 'in'], ['2026-06-11 19:00', 'out'], // jeu. : deux périodes
            ['2026-06-12 08:00', 'in'],                                                               // ven. : départ non pointé
            ['2026-06-13 09:00', 'in'], ['2026-06-13 13:00', 'out'],                                   // sam. (repos) travaillé
            ['2026-06-14 10:00', 'in'], ['2026-06-14 12:00', 'out'],                                   // dim. : +75 %
        ];
        $r = $push($kioskApi, array_map(fn ($p) => ['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'at' => $p[0], 'kind' => $p[1], 'source' => 'manual']], $week));
        $this->assertSame(array_fill(0, count($week), "applied"), array_column($r, "status"), json_encode($r[0]));
        $this->assertSame('kiosk', $r[0]['record']['source']); // la borne ne choisit pas la source
        $this->assertTrue($r[0]['record']['late_sync']); // arrivé au serveur des jours après : signalé
        $this->assertSame('2026-06-08', $r[0]['record']['date']);
        // Doublon (même sens, moins de 2 min) et heure future refusés
        $this->assertSame('rejected', $push($kioskApi, [['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'at' => '2026-06-08 08:06', 'kind' => 'in']]])[0]['status']);
        $this->assertSame('rejected', $push($kioskApi, [['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'at' => '2026-06-17 19:00', 'kind' => 'in']]])[0]['status']);
        // La borne ne corrige pas un pointage
        $this->assertSame('rejected', $push($kioskApi, [['entity' => 'pt_punches', 'record_id' => $r[0]['record']['id'], 'data' => ['at' => '2026-06-08 07:30']]])[0]['status']);

        // Pointage depuis le téléphone : pour soi seulement, à l'heure
        $self = $push($kofiApi, [['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'at' => '2026-06-17 17:58', 'kind' => 'in', 'note' => 'ignoré']]])[0];
        $this->assertSame(['applied', 'self', false, null], [$self['status'], $self['record']['source'], $self['record']['late_sync'], $self['record']['note']]);
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'at' => '2026-06-17 17:58', 'kind' => 'in']]])[0]['status']);
        $this->assertSame([$self['record']['id']], array_column($pull($kofiApi)['pt_punches']['upserts'], 'id'));
        // Pointage mobile désactivé par le responsable
        $push($api, [['entity' => 'pt_badges', 'record_id' => $eKofi, 'data' => ['self_allowed' => false]]]);
        $no = $push($kofiApi, [['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'at' => '2026-06-17 17:59', 'kind' => 'out']]])[0];
        $this->assertSame('rejected', $no['status']);
        $this->assertStringContainsString('utilisez la borne', $no['message']);

        // Saisie du responsable : motif obligatoire
        $this->assertSame('rejected', $push($api, [['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'at' => '2026-06-17 17:59', 'kind' => 'out']]])[0]['status']);
        $this->assertSame('manual', $push($api, [['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'at' => '2026-06-16 17:00', 'kind' => 'out', 'note' => 'Badge oublié']]])[0]['record']['source']);

        // Feuille de présence (durée hebdomadaire ramenée à 30 h pour l'exemple)
        $api()->putJson('/api/pointage/settings', ['weekly_hours' => 30, 'holidays' => [['date' => '2026-06-16', 'label' => 'Tabaski']]])->assertOk()
            ->assertJsonPath('data.year_holidays.2026-04-06', 'Lundi de Pâques');
        $rep = collect($api()->getJson('/api/pointage/report?from=2026-06-08&to=2026-06-14')->assertOk()->json('data.employees'))->keyBy('employee_id');
        $awa = $rep[$eAwa];
        $days = collect($awa['days'])->keyBy('date');
        $this->assertSame(['present', 475, 0], [$days['2026-06-08']['status'], $days['2026-06-08']['worked'], $days['2026-06-08']['late']]);
        $this->assertSame(['late', 545, 25], [$days['2026-06-09']['status'], $days['2026-06-09']['worked'], $days['2026-06-09']['late']]);
        $this->assertSame('absent', $days['2026-06-10']['status']);
        $this->assertSame([605, '07:55', '19:00'], [$days['2026-06-11']['worked'], $days['2026-06-11']['first_in'], $days['2026-06-11']['last_out']]);
        $this->assertSame('incomplete', $days['2026-06-12']['status']);
        $this->assertSame(240, $days['2026-06-13']['worked']);
        $t = $awa['totals'];
        $this->assertSame([1, 1, 25, 1, 1985], [$t['absences'], $t['late_count'], $t['late_minutes'], $t['incomplete'], $t['worked']]);
        $this->assertSame([65, 0, 120], [$t['ot15'], $t['ot50'], $t['ot75']]); // 1 865 min en semaine − 1 800 ; dimanche 2 h

        // Congé approuvé et jour férié : plus d'absence
        $push($api, [['entity' => 'rh_leaves', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'type' => 'sick', 'start_date' => '2026-06-10', 'end_date' => '2026-06-10', 'status' => 'approved']]]);
        $kofiWeek = collect($api()->getJson('/api/pointage/report?from=2026-06-15&to=2026-06-17')->json('data.employees'))->firstWhere('employee_id', $eKofi);
        // lun. sans pointage ; mar. (Tabaski) départ saisi sans arrivée ; mer. arrivé à 17 h 58 → en retard, toujours sur place
        $this->assertSame(['absent', 'incomplete', 'late'], array_column($kofiWeek['days'], 'status'));
        $this->assertSame(['Tabaski', true], [$kofiWeek['days'][1]['holiday'], $kofiWeek['days'][2]['open']]);
        $rep = collect($api()->getJson('/api/pointage/report?from=2026-06-08&to=2026-06-14')->json('data.employees'))->keyBy('employee_id');
        $this->assertSame('leave', collect($rep[$eAwa]['days'])->firstWhere('date', '2026-06-10')['status']);
        // L'espace salarié voit sa propre feuille
        $kofiApi()->getJson('/api/pointage/me?from=2026-06-15&to=2026-06-17')->assertOk()->assertJsonPath('data.employee_id', $eKofi);
        $kofiApi()->getJson('/api/pointage/report?from=2026-06-15&to=2026-06-17')->assertForbidden();
        $api()->get('/api/pointage/report/export?from=2026-06-08&to=2026-06-14')->assertOk()->assertHeader('content-disposition');

        // Paie de juin : heures supplémentaires ajoutées (contrat de 30 h : taux horaire = 150 000 ÷ 130 h), renvoi sans doublon
        $push($api, [['entity' => 'rh_employees', 'record_id' => $eAwa, 'data' => ['weekly_hours' => 30]]]);
        $run = $api()->postJson('/api/paie/runs', ['period' => '2026-06'])->assertCreated()->json('run');
        $api()->postJson('/api/pointage/payroll', ['run_id' => $run['id']])->assertOk();
        $api()->postJson('/api/pointage/payroll', ['run_id' => $run['id']])->assertOk();
        $slip = collect($api()->getJson("/api/paie/runs/{$run['id']}")->json('payslips'))->firstWhere('employee_id', $eAwa);
        $ot = collect($slip['items'])->where('source', 'pointage')->values();
        $this->assertCount(2, $ot);
        $this->assertEquals(round(150000 / 130 * 1.08 * 1.15), $ot[0]['amount']);
        $this->assertEquals(round(150000 / 130 * 2 * 1.75), $ot[1]['amount']);
        $this->assertStringContainsString('15 %', $ot[0]['label']);
        $api()->postJson("/api/paie/runs/{$run['id']}/validate")->assertOk();
        $api()->postJson('/api/pointage/payroll', ['run_id' => $run['id']])->assertStatus(422);

        // Salarié sorti des effectifs : badge désactivé, la borne refuse
        $push($api, [['entity' => 'rh_employees', 'record_id' => $eAwa, 'data' => ['active' => false]]]);
        $this->assertFalse(collect($pull($kioskApi)['pt_badges']['upserts'])->firstWhere('id', $eAwa)['active']);
        $this->assertSame('rejected', $push($kioskApi, [['entity' => 'pt_punches', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'at' => '2026-06-17 17:50', 'kind' => 'in']]])[0]['status']);

        // Tableau de bord
        $this->assertStringContainsString('pt_present', $api()->getJson('/api/dashboard')->assertOk()->getContent());
        $this->assertStringNotContainsString('pt_present', $kofiApi()->getJson('/api/dashboard')->assertOk()->getContent());
    }
}
