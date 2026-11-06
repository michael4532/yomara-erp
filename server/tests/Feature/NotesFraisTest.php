<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class NotesFraisTest extends TestCase
{
    public function test_expense_claims_offline_receipts_approval_accounting_and_reimbursement(): void
    {
        [$company, , $token] = $this->makeCompany('Cabinet Kouadio');
        $api = fn () => $this->api($token);
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $roleName) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@kouadio.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk(); // mise en place des modules
        [$kofi, $kofiApi] = $mk('Kofi', 'Salarié');
        [$awa, $awaApi] = $mk('Awa', 'Caissier');
        [$paul, $paulApi] = $mk('Paul', 'Comptable');
        $push = fn ($client, array $mut) => $client()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid(), 'op' => 'upsert'], $mut)])->assertOk()->json('results');
        $pull = fn ($client) => $client()->getJson('/api/sync/pull?cursor=0')->assertOk()->json('changes');

        $eKofi = $this->uuid();
        $eAwa = $this->uuid();
        $push($api, [
            ['entity' => 'rh_employees', 'record_id' => $eKofi, 'data' => ['first_name' => 'Kofi', 'last_name' => 'Yao', 'base_salary' => 200000, 'hire_date' => '2025-01-01', 'user_id' => $kofi->id]],
            ['entity' => 'rh_employees', 'record_id' => $eAwa, 'data' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'base_salary' => 150000, 'hire_date' => '2025-01-01', 'user_id' => $awa->id]],
        ]);
        $cats = collect($pull($kofiApi)['nf_categories']['upserts'])->keyBy('name');
        $this->assertCount(7, $cats);
        $this->assertEquals(15000, $cats['Repas et réceptions']['max_amount']);
        $taxi = $cats['Transport, taxi, VTC']['id'];
        $repas = $cats['Repas et réceptions']['id'];

        // Taxi saisi hors ligne et envoyé directement (pas de justificatif exigé pour cette catégorie)
        $x1 = $this->uuid();
        $r = $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $x1, 'data' => ['employee_id' => $eKofi, 'category_id' => $taxi, 'date' => now()->subDays(3)->toDateString(), 'amount' => 3500, 'description' => 'Taxi rendez-vous client Plateau', 'status' => 'submitted']]])[0];
        $this->assertSame(['applied', 'submitted'], [$r['status'], $r['record']['status']]);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $paul->id)->where('title', 'Note de frais à valider')->exists());

        // Repas au-delà du plafond : justificatif obligatoire avant l'envoi
        $x2 = $this->uuid();
        $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['employee_id' => $eKofi, 'category_id' => $repas, 'date' => now()->subDays(2)->toDateString(), 'amount' => 18000, 'description' => 'Déjeuner client Société Ivoirienne', 'merchant' => 'Maquis Le Jardin']]]);
        $no = $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['status' => 'submitted']]])[0];
        $this->assertSame('rejected', $no['status']);
        $this->assertStringContainsString('justificatif', $no['message']);
        $kofiApi()->post("/api/notes_frais/expenses/{$x2}/receipt", ['file' => UploadedFile::fake()->create('virus.exe', 10)])->assertStatus(422);
        $kofiApi()->post("/api/notes_frais/expenses/{$x2}/receipt", ['file' => UploadedFile::fake()->image('ticket.jpg', 600, 900)])->assertOk()->assertJsonPath('data.has_receipt', true);
        $r = $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['status' => 'submitted']]])[0];
        $this->assertSame(['applied', true], [$r['status'], $r['record']['over_limit']]);
        $this->assertArrayNotHasKey('receipt_path', $r['record']);

        // Droits : pas d'auto-validation, pas de dépense pour un collègue, visibilité limitée
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $x1, 'data' => ['status' => 'approved']]])[0]['status']);
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $eAwa, 'date' => now()->toDateString(), 'amount' => 1000, 'description' => 'x']]])[0]['status']);
        $this->assertSame([], $pull($awaApi)['nf_expenses']['upserts'] ?? []);
        $awaApi()->get("/api/notes_frais/expenses/{$x2}/receipt")->assertNotFound();
        $kofiApi()->get("/api/notes_frais/expenses/{$x2}/receipt")->assertOk();
        $paulApi()->get("/api/notes_frais/expenses/{$x2}/receipt")->assertOk();
        $this->assertCount(2, $pull($paulApi)['nf_expenses']['upserts']);

        // Validation : écriture de charge 618 / dette 4286
        $this->assertSame('approved', $push($paulApi, [['entity' => 'nf_expenses', 'record_id' => $x1, 'data' => ['status' => 'approved']]])[0]['record']['status']);
        $bal = fn () => collect($api()->getJson('/api/compta/balance?to='.now()->endOfMonth()->addDay()->toDateString())->json('data.rows'))->keyBy('code');
        $this->assertEquals(3500, (float) $bal()['618']['balance']);
        $this->assertEquals(-3500, (float) $bal()['4286']['balance']);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $kofi->id)->where('title', 'Note de frais approuvée')->exists());
        // Une dépense approuvée ne se modifie plus
        $this->assertSame('rejected', $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $x1, 'data' => ['amount' => 9000]]])[0]['status']);

        // Refus : motif obligatoire ; le salarié corrige (repasse en brouillon) puis renvoie
        $this->assertSame('rejected', $push($paulApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['status' => 'rejected']]])[0]['status']);
        $push($paulApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['status' => 'rejected', 'decision_note' => 'Plafond repas 15 000 F : merci de corriger']]]);
        // Une collègue ne peut pas supprimer cette note (synchronisation hors ligne) : seulement ce qu'elle voit
        $del = $awaApi()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'delete', 'entity' => 'nf_expenses', 'record_id' => $x2]]])->assertOk()->json('results.0');
        $this->assertSame('rejected', $del['status']);
        $this->assertTrue(\App\Modules\NotesFrais\Models\Expense::withoutGlobalScopes()->whereKey($x2)->exists());
        $this->assertStringContainsString('Plafond repas', AppNotification::withoutGlobalScopes()->where('user_id', $kofi->id)->where('title', 'Note de frais refusée')->value('body'));
        $r = $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['amount' => 15000, 'description' => 'Déjeuner client (part plafonnée)']]])[0];
        $this->assertSame('draft', $r['record']['status']);
        $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['status' => 'submitted']]]);
        $push($paulApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['status' => 'approved']]]);
        // Validation annulée puis refaite : l'écriture suit
        $push($paulApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['status' => 'submitted']]]);
        $this->assertEquals(-3500, (float) $bal()['4286']['balance']);
        $push($paulApi, [['entity' => 'nf_expenses', 'record_id' => $x2, 'data' => ['status' => 'approved']]]);
        $this->assertEquals(15000, (float) $bal()['6383']['balance']);
        $this->assertEquals(-18500, (float) $bal()['4286']['balance']);
        $this->assertSame('rejected', $paulApi()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'nf_expenses', 'op' => 'delete', 'record_id' => $x2]]])->json('results.0.status'));

        // Remboursement par la banque (opération de trésorerie, dette soldée)
        $api()->getJson('/api/tresorerie/summary')->assertOk();
        $bank = collect($api()->getJson('/api/tresorerie/accounts')->json('data'))->firstWhere('kind', 'bank');
        $kofiApi()->postJson('/api/notes_frais/pay', ['employee_id' => $eKofi, 'expense_ids' => [$x1], 'via' => 'treasury', 'account_id' => $bank['id']])->assertForbidden();
        $paulApi()->postJson('/api/notes_frais/pay', ['employee_id' => $eAwa, 'expense_ids' => [$x1], 'via' => 'treasury', 'account_id' => $bank['id']])->assertStatus(422);
        $paulApi()->postJson('/api/notes_frais/pay', ['employee_id' => $eKofi, 'expense_ids' => [$x1, $x2], 'via' => 'treasury', 'account_id' => $bank['id']])->assertOk()->assertJsonPath('data.total', 18500);
        $this->assertEquals(0.0, (float) ($bal()['4286']['balance'] ?? 0));
        $sum = collect($api()->getJson('/api/tresorerie/summary')->json('data'))->firstWhere('id', $bank['id']);
        $this->assertEquals(-18500, $sum['balance']);
        $this->assertSame(['paid', 'paid'], array_column(array_filter($pull($kofiApi)['nf_expenses']['upserts'], fn ($e) => in_array($e['id'], [$x1, $x2])), 'status'));
        $paulApi()->postJson('/api/notes_frais/pay', ['employee_id' => $eKofi, 'expense_ids' => [$x1], 'via' => 'treasury', 'account_id' => $bank['id']])->assertStatus(422);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $kofi->id)->where('title', 'Notes de frais remboursées')->exists());

        // Remboursement sur la paie : hors salaire (brut inchangé, net augmenté), remis « à rembourser » si la paie est supprimée
        $x3 = $this->uuid();
        $push($kofiApi, [['entity' => 'nf_expenses', 'record_id' => $x3, 'data' => ['employee_id' => $eKofi, 'category_id' => $taxi, 'date' => now()->toDateString(), 'amount' => 6000, 'description' => 'Taxis de la semaine', 'status' => 'submitted']]]);
        $push($paulApi, [['entity' => 'nf_expenses', 'record_id' => $x3, 'data' => ['status' => 'approved']]]);
        $run = $api()->postJson('/api/paie/runs', ['period' => now()->format('Y-m')])->assertCreated()->json('run');
        $before = collect($api()->getJson("/api/paie/runs/{$run['id']}")->json('payslips'))->firstWhere('employee_id', $eKofi);
        $paulApi()->postJson('/api/notes_frais/pay', ['employee_id' => $eKofi, 'expense_ids' => [$x3], 'via' => 'payroll', 'run_id' => $run['id']])->assertOk();
        $after = collect($api()->getJson("/api/paie/runs/{$run['id']}")->json('payslips'))->firstWhere('employee_id', $eKofi);
        $this->assertEquals($before['gross'], $after['gross']);
        $this->assertEquals($before['net'] + 6000, $after['net']);
        $this->assertEquals(6000, $after['result']['totals']['reimbursements']);
        $api()->deleteJson("/api/paie/runs/{$run['id']}")->assertOk();
        $this->assertSame('approved', collect($pull($kofiApi)['nf_expenses']['upserts'])->firstWhere('id', $x3)['status']);

        // Paie validée : 4286 débité du remboursement, équilibre de l'écriture de paie
        $run = $api()->postJson('/api/paie/runs', ['period' => now()->format('Y-m')])->assertCreated()->json('run');
        $paulApi()->postJson('/api/notes_frais/pay', ['employee_id' => $eKofi, 'expense_ids' => [$x3], 'via' => 'payroll', 'run_id' => $run['id']])->assertOk();
        $api()->postJson("/api/paie/runs/{$run['id']}/validate")->assertOk();
        $this->assertEquals(0.0, (float) ($bal()['4286']['balance'] ?? 0));

        // Tableau de bord du comptable
        $this->assertStringContainsString('nf_to_pay', $paulApi()->getJson('/api/dashboard')->assertOk()->getContent());
        $this->assertStringNotContainsString('nf_to_pay', $kofiApi()->getJson('/api/dashboard')->getContent());
    }
}
