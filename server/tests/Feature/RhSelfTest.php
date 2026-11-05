<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class RhSelfTest extends TestCase
{
    public function test_offline_hr_employee_space_visibility_leave_requests_and_payslips(): void
    {
        [$company, , $token] = $this->makeCompany('Boulangerie Ama');
        $api = fn () => $this->api($token);
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $roleName) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@ama.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        [$kofi, $kofiApi] = $mk('Kofi', 'Salarié');
        [$awa, $awaApi] = $mk('Awa', 'Caissier');
        [$rh, $rhApi] = $mk('Nadia', 'RH / Paie');

        $push = fn ($client, array $mut) => $client()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid(), 'op' => 'upsert'], $mut)])->assertOk()->json('results');

        // Fiches créées hors ligne par la RH (matricule automatique, compte relié)
        $eKofi = $this->uuid();
        $eAwa = $this->uuid();
        $r = $push($rhApi, [
            ['entity' => 'rh_employees', 'record_id' => $eKofi, 'data' => ['first_name' => 'Kofi', 'last_name' => 'Yao', 'base_salary' => 150000, 'hire_date' => now()->subYears(2)->toDateString(), 'user_id' => $kofi->id, 'leave_opening_balance' => 10]],
            ['entity' => 'rh_employees', 'record_id' => $eAwa, 'data' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'base_salary' => 120000, 'hire_date' => now()->subYear()->toDateString()]],
        ]);
        $this->assertSame(['applied', 'applied'], array_column($r, 'status'));
        $this->assertSame('EMP00001', $r[0]['record']['matricule']);
        // Un compte ne peut être relié qu'à une fiche
        $this->assertSame('rejected', $push($rhApi, [['entity' => 'rh_employees', 'record_id' => $eAwa, 'data' => ['user_id' => $kofi->id]]])[0]['status']);
        $push($rhApi, [['entity' => 'rh_employees', 'record_id' => $eAwa, 'data' => ['user_id' => $awa->id]]]);

        // Visibilité : le salarié ne reçoit que sa fiche, la RH reçoit tout
        $pull = fn ($client) => $client()->getJson('/api/sync/pull?cursor=0')->assertOk()->json('changes');
        $ch = $pull($kofiApi);
        $this->assertSame([$eKofi], array_column($ch['rh_employees']['upserts'], 'id'));
        $this->assertContains($eAwa, $ch['rh_employees']['deletes']);
        $this->assertCount(2, $pull($rhApi)['rh_employees']['upserts']);
        // Le salarié ne modifie pas sa fiche par la synchronisation
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'rh_employees', 'record_id' => $eKofi, 'data' => ['base_salary' => 900000]]])[0]['status']);

        // Demande de congé hors ligne par le salarié : statut forcé à « demandé », jours calculés, RH prévenue
        $l1 = $this->uuid();
        $start = now()->next('Monday');
        $r = $push($kofiApi, [['entity' => 'rh_leaves', 'record_id' => $l1, 'data' => ['employee_id' => $eKofi, 'type' => 'annual', 'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(6)->toDateString(), 'status' => 'approved', 'reason' => 'Mariage de ma sœur']]])[0];
        $this->assertSame('applied', $r['status']);
        $this->assertSame('requested', $r['record']['status']);
        $this->assertEquals(6, $r['record']['days']); // lundi → dimanche = 6 jours ouvrables
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $rh->id)->where('title', 'Demande de congé')->exists());
        // Pour un collègue : refusé ; chevauchement : refusé ; s'approuver soi-même : refusé
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'rh_leaves', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'type' => 'annual', 'start_date' => $start->toDateString(), 'end_date' => $start->toDateString()]]])[0]['status']);
        $over = $push($kofiApi, [['entity' => 'rh_leaves', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eKofi, 'type' => 'exceptional', 'start_date' => $start->copy()->addDays(2)->toDateString(), 'end_date' => $start->copy()->addDays(3)->toDateString()]]])[0];
        $this->assertSame('rejected', $over['status']);
        $this->assertStringContainsString('couvre déjà cette période', $over['message']);
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'rh_leaves', 'record_id' => $l1, 'data' => ['status' => 'approved']]])[0]['status']);
        // Awa ne voit pas la demande de Kofi
        $this->assertSame([], array_column($pull($awaApi)['rh_leaves']['upserts'] ?? [], 'id'));
        $this->assertCount(1, $pull($kofiApi)['rh_leaves']['upserts']);

        // La RH approuve (avec un mot) → le salarié est prévenu ; il ne peut plus l'annuler lui-même
        $r = $push($rhApi, [['entity' => 'rh_leaves', 'record_id' => $l1, 'data' => ['status' => 'approved', 'decision_note' => 'Bon séjour']]])[0];
        $this->assertSame('approved', $r['record']['status']);
        $this->assertSame($rh->id, $r['record']['decided_by']);
        $n = AppNotification::withoutGlobalScopes()->where('user_id', $kofi->id)->where('title', 'Congé approuvé')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('Bon séjour', $n->body);
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'rh_leaves', 'record_id' => $l1, 'data' => ['status' => 'cancelled']]])[0]['status']);
        // Une demande encore en attente s'annule par le salarié ; il ne peut pas la supprimer
        $l2 = $this->uuid();
        $later = $start->copy()->addWeeks(4);
        $push($kofiApi, [['entity' => 'rh_leaves', 'record_id' => $l2, 'data' => ['employee_id' => $eKofi, 'type' => 'exceptional', 'start_date' => $later->toDateString(), 'end_date' => $later->toDateString()]]]);
        $this->assertSame('applied', $push($kofiApi, [['entity' => 'rh_leaves', 'record_id' => $l2, 'data' => ['status' => 'cancelled']]])[0]['status']);
        $this->assertSame('rejected', $kofiApi()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'rh_leaves', 'op' => 'delete', 'record_id' => $l2]]])->json('results.0.status'));

        // Espace salarié : fiche, solde, coordonnées ; pas de bulletin avant validation de la paie
        $me = $kofiApi()->getJson('/api/rh/me')->assertOk()->json();
        $this->assertSame($eKofi, $me['employee']['id']);
        $this->assertEquals(10 + (int) floor(now()->startOfYear()->diffInMonths(now())) * 2.2 - ($start->year === now()->year ? 6 : 0), $me['leave_balance']);
        $this->assertSame([], $me['payslips']);
        $kofiApi()->putJson('/api/rh/me', ['phone' => '+225 07 11 22 33 44', 'emergency_contact' => 'Ama Yao (mère) 07 00 00 00 00', 'base_salary' => 1])->assertOk()->assertJsonPath('phone', '+225 07 11 22 33 44')->assertJsonPath('base_salary', 150000);
        $rhApi()->getJson('/api/rh/employees')->assertOk();
        $kofiApi()->getJson('/api/rh/employees')->assertForbidden();

        // Compte non relié
        [, $loneApi] = $mk('Lassina', 'Salarié');
        $loneApi()->getJson('/api/rh/me')->assertOk()->assertJson(['linked' => false, 'employee' => null]);

        // Paie validée → le bulletin apparaît dans l'espace du salarié, pas celui du collègue
        $run = $rhApi()->postJson('/api/paie/runs', ['period' => now()->format('Y-m')])->assertCreated()->json('run');
        $this->assertSame([], $kofiApi()->getJson('/api/rh/me')->json('payslips'));
        $rhApi()->postJson("/api/paie/runs/{$run['id']}/validate")->assertOk();
        $slips = $kofiApi()->getJson('/api/rh/me')->json('payslips');
        $this->assertCount(1, $slips);
        $this->assertEquals(153000, $slips[0]['gross']); // 150 000 + 2 % d'ancienneté
        $kofiApi()->getJson("/api/rh/me/payslips/{$slips[0]['id']}")->assertOk()->assertJsonPath('employee_id', $eKofi)->assertJsonStructure(['result', 'run']);
        $awaSlip = collect($rhApi()->getJson("/api/paie/runs/{$run['id']}")->json('payslips'))->firstWhere('employee_id', $eAwa);
        $kofiApi()->getJson("/api/rh/me/payslips/{$awaSlip['id']}")->assertNotFound();
        $kofiApi()->getJson("/api/paie/runs/{$run['id']}")->assertForbidden();

        // Fiche avec bulletins : suppression refusée, sortie des effectifs à la place
        $this->assertSame('rejected', $rhApi()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'rh_employees', 'op' => 'delete', 'record_id' => $eAwa]]])->json('results.0.status'));
        $rhApi()->deleteJson("/api/rh/employees/{$eAwa}")->assertOk();
        $this->assertFalse($rhApi()->getJson("/api/rh/employees/{$eAwa}")->json('active'));

        // Rôles : chaque rôle existant reçoit l'espace salarié
        $this->assertContains('rh.self', Role::withoutGlobalScopes()->find($role('Caissier'))->permissions);
    }
}
