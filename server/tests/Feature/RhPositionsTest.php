<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RhPositionsTest extends TestCase
{
    public function test_categories_positions_fill_employee_and_guard_deletes(): void
    {
        [$company, , $token] = $this->makeCompany('Boulangerie Akwaba');
        $api = fn () => $this->api($token);
        $push = fn (array $mut) => $api()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid(), 'op' => $m['op'] ?? 'upsert'], $mut)])->assertOk()->json('results');

        $cat = $this->uuid();
        $boss = $this->uuid();
        $pos = $this->uuid();
        $emp = $this->uuid();
        $r = $push([
            ['entity' => 'rh_categories', 'record_id' => $cat, 'data' => ['code' => 'AM2', 'label' => 'Agent de maîtrise 2', 'group' => 'Agents de maîtrise', 'min_salary' => 180000]],
            ['entity' => 'rh_positions', 'record_id' => $boss, 'data' => ['title' => 'Directeur de production', 'department' => 'Production']],
            ['entity' => 'rh_positions', 'record_id' => $pos, 'data' => ['title' => 'Chef boulanger', 'department' => 'Production', 'category_id' => $cat, 'parent_id' => $boss, 'headcount' => 2,
                'missions' => "Organiser la production\nContrôler la qualité", 'salary_min' => 180000, 'salary_max' => 260000]],
            ['entity' => 'rh_employees', 'record_id' => $emp, 'data' => ['first_name' => 'Yao', 'last_name' => 'Kouassi', 'position_id' => $pos, 'base_salary' => 200000]],
        ]);
        $this->assertSame(['applied', 'applied', 'applied', 'applied'], array_column($r, 'status'));

        // La fiche salarié reprend la fonction, le service et la catégorie du poste
        $e = DB::table('rh_employees')->where('id', $emp)->first();
        $this->assertSame(['Chef boulanger', 'Production', 'AM2'], [$e->job_title, $e->department, $e->category]);

        // Doublon de code, boucle d'organigramme, fourchette inversée : refusés
        $this->assertSame('rejected', $push([['entity' => 'rh_categories', 'record_id' => $this->uuid(), 'data' => ['code' => 'AM2']]])[0]['status']);
        $this->assertSame('rejected', $push([['entity' => 'rh_positions', 'record_id' => $boss, 'data' => ['parent_id' => $pos]]])[0]['status']);
        $this->assertSame('rejected', $push([['entity' => 'rh_positions', 'record_id' => $this->uuid(), 'data' => ['title' => 'X', 'salary_min' => 300000, 'salary_max' => 100000]]])[0]['status']);

        // Suppressions protégées : poste occupé, poste avec subordonnés, catégorie utilisée
        $this->assertSame('rejected', $push([['entity' => 'rh_positions', 'op' => 'delete', 'record_id' => $pos]])[0]['status']);
        $this->assertSame('rejected', $push([['entity' => 'rh_positions', 'op' => 'delete', 'record_id' => $boss]])[0]['status']);
        $this->assertSame('rejected', $push([['entity' => 'rh_categories', 'op' => 'delete', 'record_id' => $cat]])[0]['status']);

        // Synchronisation : postes et catégories partent vers les appareils
        $changes = $api()->getJson('/api/sync/pull?cursor=0')->assertOk()->json('changes');
        $this->assertCount(2, $changes['rh_positions']['upserts']);
        $this->assertSame('AM2', $changes['rh_categories']['upserts'][0]['code']);
    }

    public function test_employee_contracts_need_the_explicit_permission(): void
    {
        [$company, , $token] = $this->makeCompany('Clinique Akwaba');
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $rh = User::create(['company_id' => $company->id, 'name' => 'Nadia', 'email' => 'nadia@akwaba.ci', 'password' => 'secret123', 'role_id' => $role('RH / Paie')]);
        $rhApi = fn () => $this->api($rh->fresh()->createToken('t')->plainTextToken);

        $emp = $this->uuid();
        $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'rh_employees', 'record_id' => $emp,
            'data' => ['first_name' => 'Awa', 'last_name' => 'Koné', 'contract_type' => 'CDD', 'hire_date' => now()->subMonths(5)->toDateString(), 'end_date' => now()->addDays(10)->toDateString()]]]])->assertOk();
        DB::table('jr_contracts')->insert(['company_id' => $company->id, 'title' => 'CDD infirmière', 'kind' => 'employment', 'status' => 'active', 'employee_id' => $emp,
            'start_date' => now()->subMonths(5)->toDateString(), 'end_date' => now()->addDays(10)->toDateString(), 'body' => 'Article 1 : engagement', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('jr_contracts')->insert(['company_id' => $company->id, 'title' => 'Bail du siège', 'kind' => 'lease', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        // Le rôle RH (« rh.* ») ne suffit pas : ni la liste, ni les contrats dans le dossier
        $rhApi()->getJson('/api/rh/contracts')->assertForbidden();
        $this->assertSame([], $rhApi()->getJson("/api/rh/employees/$emp/dossier")->assertOk()->json('contracts'));

        // L'administrateur lui accorde le droit : contrats de travail seulement, CDD qui se termine signalé
        $rh->forceFill(['permission_grants' => ['rh.contracts']])->save();
        $res = $rhApi()->getJson('/api/rh/contracts')->assertOk()->json();
        $this->assertSame(['CDD infirmière'], array_column($res['data'], 'title'));
        $this->assertSame(['Koné Awa', 'soon'], [$res['data'][0]['employee'], $res['data'][0]['alert']]);
        $this->assertSame('Koné Awa', $res['ending_cdd'][0]['employee']);
        $this->assertSame([], $res['without_contract']);
        $id = $res['data'][0]['id'];
        $this->assertSame('Article 1 : engagement', $rhApi()->getJson("/api/rh/contracts/juridique/$id")->assertOk()->json('data.body'));
        $this->assertCount(1, $rhApi()->getJson("/api/rh/employees/$emp/dossier")->json('contracts'));
    }
}
