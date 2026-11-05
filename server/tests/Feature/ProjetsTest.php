<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProjetsTest extends TestCase
{
    protected string $token;

    protected $company;

    protected $admin;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, $this->admin, $this->token] = $this->makeCompany('Studio Web');
        $this->api($this->token)->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['prj_tasks']);
    }

    protected function member(string $name, string $role): array
    {
        $u = User::create(['company_id' => $this->company->id, 'role_id' => Role::withoutGlobalScopes()->where('company_id', $this->company->id)->where('name', $role)->value('id'),
            'name' => $name, 'email' => Str::slug($name).'@studio.ci', 'password' => 'secret123']);

        return [$u, $u->createToken('t')->plainTextToken];
    }

    public function test_project_tasks_time_and_invoicing(): void
    {
        $api = fn () => $this->api($this->token);
        [$dev, $devToken] = $this->member('Koffi Dev', 'Équipe projet');
        $client = $api()->postJson('/api/core/partners', ['name' => 'Hôtel Lagune', 'type' => 'customer'])->assertCreated()->json('data');

        $p = $api()->postJson('/api/projets/projects', ['name' => 'Site web Hôtel Lagune', 'code' => 'LAG-01', 'partner_id' => $client['id'], 'billing_mode' => 'time',
            'hourly_rate' => 15000, 'budget_hours' => 40, 'member_ids' => [$dev->id], 'start_date' => now()->toDateString(), 'end_date' => now()->addMonth()->toDateString()])->assertCreated()->json('data');
        $this->assertSame('active', $p['status']);
        $this->assertSame($this->admin->id, $p['manager_id']);

        $ms = $api()->postJson('/api/projets/milestones', ['project_id' => $p['id'], 'name' => 'Maquettes validées', 'amount' => 300000, 'due_date' => now()->addWeek()->toDateString()])->assertCreated()->json('data');
        $t1 = $api()->postJson('/api/projets/tasks', ['project_id' => $p['id'], 'title' => 'Maquettes', 'assignee_id' => $dev->id, 'estimate_hours' => 8, 'due_date' => now()->subDay()->toDateString(), 'milestone_id' => $ms['id'],
            'checklist' => [['text' => 'Accueil'], ['text' => 'Chambres', 'done' => true]]])->assertCreated()->json('data');
        $t2 = $api()->postJson('/api/projets/tasks', ['project_id' => $p['id'], 'title' => 'Intégration', 'estimate_hours' => 16])->assertCreated()->json('data');
        $this->assertSame('todo', $t1['status']);
        // Le collaborateur est prévenu
        $this->assertSame(1, \App\Models\AppNotification::withoutGlobalScopes()->where('user_id', $dev->id)->where('type', 'task')->count());

        // Le collaborateur fait avancer SA tâche et saisit son temps, pas celui des autres
        $dapi = fn () => $this->api($devToken);
        $t = $dapi()->putJson("/api/projets/tasks/{$t1['id']}", ['status' => 'done'])->assertOk()->json('data');
        $this->assertNotNull($t['done_at']);
        $dapi()->putJson("/api/projets/tasks/{$t2['id']}", ['assignee_id' => $this->admin->id])->assertStatus(422);
        $dapi()->postJson('/api/projets/projects', ['name' => 'Interdit'])->assertForbidden();
        $dapi()->postJson('/api/projets/timesheets', ['project_id' => $p['id'], 'task_id' => $t1['id'], 'date' => now()->toDateString(), 'hours' => 6.5, 'description' => 'Maquettes accueil'])->assertCreated();
        $dapi()->postJson('/api/projets/timesheets', ['project_id' => $p['id'], 'user_id' => $this->admin->id, 'date' => now()->toDateString(), 'hours' => 2])->assertStatus(422);
        $api()->postJson('/api/projets/timesheets', ['project_id' => $p['id'], 'task_id' => $t2['id'], 'date' => now()->subDay()->toDateString(), 'hours' => 3])->assertCreated();
        $api()->postJson('/api/projets/timesheets', ['project_id' => $p['id'], 'date' => now()->toDateString(), 'hours' => 1, 'billable' => false, 'description' => 'Réunion interne'])->assertCreated();

        $s = collect($api()->getJson('/api/projets/stats')->assertOk()->json('projects'))->firstWhere('id', $p['id']);
        $this->assertSame(2, $s['tasks']);
        $this->assertSame(50, $s['progress']);
        $this->assertEquals(10.5, $s['hours']);
        $this->assertEquals(9.5, $s['to_invoice_hours']);
        $this->assertEquals(142500, $s['to_invoice_amount']);
        $this->assertEquals(26, $s['budget_used']); // 10,5 h / 40 h
        $this->assertSame(0, $s['late']); // la tâche en retard est terminée
        $this->assertSame('Koffi Dev', $s['by_user'][0]['name']);

        // Facture du temps passé (groupée par tâche)
        $inv = $api()->postJson("/api/projets/projects/{$p['id']}/invoice", ['group_by' => 'task'])->assertOk()->json('invoice');
        $this->assertSame('invoice', $inv['type']);
        $this->assertSame('draft', $inv['status']);
        $this->assertSame($client['id'], $inv['partner_id']);
        $this->assertCount(2, $inv['lines']);
        $this->assertEquals(142500 * 1.18, $inv['total']);
        $byDesc = collect($inv['lines'])->keyBy('description');
        $this->assertEquals(6.5, $byDesc['Site web Hôtel Lagune — Maquettes']['quantity']);
        $this->assertSame('h', $byDesc['Site web Hôtel Lagune — Intégration']['unit']);
        // Temps verrouillés, plus rien à facturer
        $ts = collect($api()->getJson('/api/projets/timesheets')->json('data'))->firstWhere('hours', 6.5);
        $dapi()->putJson("/api/projets/timesheets/{$ts['id']}", ['hours' => 7])->assertStatus(422);
        $api()->postJson("/api/projets/projects/{$p['id']}/invoice")->assertStatus(422);
        // Facture brouillon supprimée → temps de nouveau facturables
        $api()->deleteJson("/api/ventes/documents/{$inv['id']}")->assertOk();
        $this->assertEquals(9.5, collect($api()->getJson('/api/projets/stats')->json('projects'))->firstWhere('id', $p['id'])['to_invoice_hours']);

        // Jalon (forfait)
        $inv2 = $api()->postJson("/api/projets/projects/{$p['id']}/invoice", ['milestone_id' => $ms['id']])->assertOk()->json('invoice');
        $this->assertEquals(300000, $inv2['subtotal']);
        $this->assertSame('invoiced', collect($api()->getJson('/api/projets/milestones')->json('data'))->firstWhere('id', $ms['id'])['status']);
        $api()->postJson("/api/projets/projects/{$p['id']}/invoice", ['milestone_id' => $ms['id']])->assertStatus(422);

        // Le tableau de bord expose les indicateurs du module
        $dash = collect($api()->getJson('/api/dashboard')->assertOk()->json('kpis') ?? $api()->getJson('/api/dashboard')->json('data'))->keyBy('key');
        $this->assertSame(1, $dash['prj_active']['value'] ?? null);
    }
}
