<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TalentsTest extends TestCase
{
    public function test_review_workflow_trainings_checklists_and_deadlines(): void
    {
        [$company, , $token] = $this->makeCompany('Akwaba Talents');
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = fn (string $name, string $email) => User::create(['company_id' => $company->id, 'name' => $name, 'email' => $email, 'password' => 'secret123', 'role_id' => $role('Salarié')]);
        $chefU = $mk('Chef', 'chef@akwaba.ci');
        $ayaU = $mk('Aya', 'aya@akwaba.ci');
        $api = fn (?User $u = null) => $this->api($u ? $u->fresh()->createToken('t')->plainTextToken : $token);
        $push = fn ($c, array $m) => $c->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert'] + $m]])->assertOk()->json('results.0');

        [$chef, $aya, $paul, $dept] = [$this->uuid(), $this->uuid(), $this->uuid(), $this->uuid()];
        foreach ([
            ['entity' => 'rh_employees', 'record_id' => $chef, 'data' => ['first_name' => 'Kouamé', 'last_name' => 'Chef', 'user_id' => $chefU->id]],
            ['entity' => 'rh_departments', 'record_id' => $dept, 'data' => ['name' => 'Marketing', 'head_ids' => [$chef]]],
            ['entity' => 'rh_employees', 'record_id' => $aya, 'data' => ['first_name' => 'Aya', 'last_name' => 'Koffi', 'user_id' => $ayaU->id, 'department_id' => $dept, 'hire_date' => now()->subYears(3)->toDateString(), 'birth_date' => now()->subYears(28)->toDateString()]],
            ['entity' => 'rh_employees', 'record_id' => $paul, 'data' => ['first_name' => 'Paul', 'last_name' => 'Autre']],
            ['entity' => 'rh_contracts', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $aya, 'type' => 'CDD', 'start_date' => now()->subYears(3)->toDateString(), 'end_date' => now()->addDays(10)->toDateString(), 'trial_end_date' => now()->addDays(5)->toDateString()]],
        ] as $m) {
            $this->assertSame('applied', $push($api(), $m)['status'], json_encode($m));
        }

        // Le responsable planifie l'entretien de sa collaboratrice ; pas celui d'un autre service
        $rid = $this->uuid();
        $r = $push($api($chefU), ['entity' => 'tal_reviews', 'record_id' => $rid, 'data' => ['employee_id' => $aya, 'type' => 'annual', 'scheduled_on' => now()->addDays(3)->toDateString()]]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame($chef, $r['record']['reviewer_id']);
        $this->assertCount(7, $r['record']['ratings']); // grille de compétences par défaut
        $this->assertSame('rejected', $push($api($chefU), ['entity' => 'tal_reviews', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $paul]])['status']);

        // Auto-évaluation de la salariée : elle ne peut pas se noter à la place du responsable
        $ratings = array_map(fn ($x) => ['criterion' => $x['criterion'], 'self' => 4, 'score' => 5], $r['record']['ratings']);
        $r = $push($api($ayaU), ['entity' => 'tal_reviews', 'record_id' => $rid, 'data' => ['ratings' => $ratings, 'employee_comment' => 'Bonne année', 'status' => 'manager', 'manager_comment' => 'piratage']]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $row = DB::table('tal_reviews')->where('id', $rid)->first();
        $this->assertSame(['manager', null], [$row->status, $row->manager_comment]);
        $this->assertNull(json_decode($row->ratings, true)[0]['score']);
        $this->assertSame('rejected', $push($api($ayaU), ['entity' => 'tal_reviews', 'record_id' => $rid, 'data' => ['employee_comment' => 'modif']])['status']);

        // Évaluation du responsable : note globale (compétences et objectifs pondérés)
        $ratings = array_map(fn ($x) => ['criterion' => $x['criterion'], 'self' => 4, 'score' => 4], $ratings);
        $r = $push($api($chefU), ['entity' => 'tal_reviews', 'record_id' => $rid, 'data' => ['ratings' => $ratings, 'objectives' => [['title' => 'Lancer la campagne', 'weight' => 2, 'score' => 5], ['title' => 'Former un stagiaire', 'weight' => 1, 'score' => 2]],
            'manager_comment' => 'Très bien', 'outcome' => 'raise', 'status' => 'done']]);
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertEqualsWithDelta((4 + 4) / 2, (float) DB::table('tal_reviews')->where('id', $rid)->value('score'), 0.01); // (4 + (5×2+2)/3) / 2 = 4
        $this->assertNotNull(DB::table('tal_reviews')->where('id', $rid)->value('completed_at'));

        // Un collègue d'un autre service ne voit pas l'entretien
        $paulU = $mk('Paul', 'paul@akwaba.ci');
        DB::table('rh_employees')->where('id', $paul)->update(['user_id' => $paulU->id]);
        $this->assertEmpty($api($paulU)->getJson('/api/sync/pull?cursor=0&limit=2000')->assertOk()->json('changes.tal_reviews.upserts') ?? []);
        $mine = $api($ayaU)->getJson('/api/sync/pull?cursor=0&limit=2000')->json('changes.tal_reviews.upserts');
        $this->assertSame([$rid], array_column($mine, 'id'));

        // Formation : participants, prise en charge, contrôles
        $this->assertSame('rejected', $push($api(), ['entity' => 'tal_trainings', 'record_id' => $this->uuid(), 'data' => ['title' => 'Excel', 'cost' => 100000, 'funded_amount' => 150000]])['status']);
        $t = $push($api(), ['entity' => 'tal_trainings', 'record_id' => $this->uuid(), 'data' => ['title' => 'Excel avancé', 'start_date' => now()->addDays(20)->toDateString(), 'hours' => 14, 'cost' => 300000, 'funded' => true, 'funded_amount' => 200000,
            'participants' => [['employee_id' => $aya], ['employee_id' => $paul]]]]);
        $this->assertSame('applied', $t['status'], json_encode($t));
        $this->assertSame('rejected', $push($api($ayaU), ['entity' => 'tal_trainings', 'record_id' => $this->uuid(), 'data' => ['title' => 'Moi']])['status']);

        // Parcours d'intégration : modèle par défaut, la salariée coche ses tâches seulement
        $cid = $this->uuid();
        $c = $push($api(), ['entity' => 'tal_checklists', 'record_id' => $cid, 'data' => ['employee_id' => $aya, 'kind' => 'onboarding']]);
        $this->assertSame('applied', $c['status'], json_encode($c));
        $items = $c['record']['items'];
        $this->assertCount(11, $items);
        $this->assertSame('rejected', $push($api(), ['entity' => 'tal_checklists', 'record_id' => $this->uuid(), 'data' => ['employee_id' => $aya, 'kind' => 'onboarding']])['status']);
        $all = array_map(fn ($i) => ['done' => true] + $i, $items);
        $c = $push($api($ayaU), ['entity' => 'tal_checklists', 'record_id' => $cid, 'data' => ['items' => $all]]);
        $this->assertSame('applied', $c['status'], json_encode($c));
        $saved = collect(json_decode(DB::table('tal_checklists')->where('id', $cid)->value('items'), true));
        $this->assertSame($saved->where('owner', 'employee')->count(), $saved->where('done', true)->count());
        $this->assertSame('open', DB::table('tal_checklists')->where('id', $cid)->value('status'));
        $c = $push($api(), ['entity' => 'tal_checklists', 'record_id' => $cid, 'data' => ['items' => $all]]);
        $this->assertSame(['done', 100], [DB::table('tal_checklists')->where('id', $cid)->value('status'), $c['record']['progress']]);

        // Échéances RH : fin de CDD, fin d'essai, anniversaires, entretien
        $d = $api()->getJson('/api/rh/deadlines')->assertOk()->json();
        $kinds = array_column($d['items'], 'kind');
        foreach (['contract_end', 'trial_end', 'work_anniversary', 'birthday'] as $k) {
            $this->assertContains($k, $kinds);
        }
        $this->assertSame('danger', $d['items'][0]['level']); // CDD qui finit dans 10 jours en tête
        $api($ayaU)->getJson('/api/rh/deadlines')->assertForbidden();
    }
}
