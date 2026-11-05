<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Maintenance\MaintenanceHooks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Maintenance : panne signalée, ordre de travail, technicien, plan préventif par date et par compteur, indicateurs. */
class MaintenanceTest extends TestCase
{
    public function test_breakdown_request_work_order_and_preventive_plans(): void
    {
        // Heure fixe en journée : le test avance de 3 h et ne doit pas changer de jour (relevés datés)
        $this->travelTo(now()->startOfDay()->addHours(9));
        [$company, $admin, $token] = $this->makeCompany('Usine Maintenance SA');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/maintenance/schema')->assertOk();
        $role = fn ($n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $this->assertNotNull($role('Technicien maintenance'));
        $tech = User::create(['company_id' => $company->id, 'name' => 'Ouattara Tech', 'email' => 'tech@mt.ci', 'password' => 'secret123', 'role_id' => $role('Technicien maintenance')]);
        $vendeur = User::create(['company_id' => $company->id, 'name' => 'Aya Vente', 'email' => 'aya@mt.ci', 'password' => 'secret123', 'role_id' => $role('Commercial')]);
        $tapi = fn () => $this->api($tech->createToken('t')->plainTextToken);
        $vapi = fn () => $this->api($vendeur->createToken('t')->plainTextToken);

        $ge = $api()->postJson('/api/maintenance/records/equipment', ['title' => 'Groupe électrogène 100 kVA', 'code' => 'GE-01', 'criticality' => 'high', 'meter_unit' => 'h', 'meter_value' => 1180, 'owner_id' => $tech->id])
            ->assertCreated()->json('data');
        $clim = $api()->postJson('/api/maintenance/records/equipment', ['title' => 'Climatiseur salle serveurs', 'location' => 'Siège'])->assertCreated()->json('data');

        // une vendeuse signale une panne : équipement critique à l'arrêt → urgent, responsable prévenu
        $o = $vapi()->postJson('/api/maintenance/records/orders', ['title' => 'Le groupe ne démarre plus', 'equipment_id' => $ge['id'], 'equipment_stopped' => true, 'assignee_id' => $tech->id])
            ->assertCreated()->assertJsonPath('data.status', 'requested')->assertJsonPath('data.priority', 'urgent')->assertJsonPath('data.assignee_id', null)->json('data');
        $this->assertSame('OT-'.now()->format('Y').'-0001', $o['number']);
        $this->assertSame('down', DB::table('mt_equipment')->find($ge['id'])->status);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Panne urgente%')->exists());
        $vapi()->getJson('/api/maintenance/records/orders')->assertOk()->assertJsonCount(1, 'data');
        $vapi()->postJson('/api/maintenance/orders/'.$o['id'].'/approve')->assertStatus(422);
        $vapi()->postJson('/api/maintenance/records/equipment', ['title' => 'X'])->assertForbidden();

        // le technicien ne voit pas encore l'OT ; le responsable le lui confie
        $tapi()->postJson('/api/maintenance/orders/'.$o['id'].'/start')->assertStatus(422);
        $api()->postJson('/api/maintenance/orders/'.$o['id'].'/approve', ['assignee_id' => $tech->id])->assertOk()->assertJsonPath('data.status', 'planned');
        $this->assertTrue(AppNotification::where('user_id', $tech->id)->where('title', 'like', 'Intervention confiée%')->exists());
        $tapi()->putJson('/api/maintenance/records/orders/'.$o['id'], ['assignee_id' => $admin->id])->assertStatus(422);
        $tapi()->postJson('/api/maintenance/orders/'.$o['id'].'/start')->assertOk()->assertJsonPath('data.status', 'in_progress');
        $tapi()->postJson('/api/maintenance/orders/'.$o['id'].'/hold', ['note' => 'Attente démarreur'])->assertOk()->assertJsonPath('data.status', 'on_hold');
        $this->travel(3)->hours();
        $done = $tapi()->postJson('/api/maintenance/orders/'.$o['id'].'/finish', ['report' => 'Démarreur remplacé.', 'cause' => 'electrical', 'parts_cost' => 85000, 'labour_cost' => 20000, 'meter_reading' => 1195])
            ->assertOk()->assertJsonPath('data.status', 'done')->json('data');
        $this->assertEquals(105000, $done['total_cost']);
        $this->assertEqualsWithDelta(3, (float) $done['downtime_hours'], 0.1);
        $this->assertSame('running', DB::table('mt_equipment')->find($ge['id'])->status);
        $this->assertEquals(1195, DB::table('mt_equipment')->find($ge['id'])->meter_value);
        $this->assertTrue(AppNotification::where('user_id', $vendeur->id)->where('title', 'Intervention terminée')->exists());
        $this->assertStringStartsWith('1 panne(s) · 3 h d\'arrêt · 105 000', $api()->getJson('/api/maintenance/records/equipment/'.$ge['id'])->json('data.history'));
        $this->travel(-3)->hours();

        // plan par date : jamais fait → OT créé dans le délai ; terminé → échéance suivante
        $p = $api()->postJson('/api/maintenance/records/plans', ['title' => 'Nettoyage des filtres', 'equipment_id' => $clim['id'], 'every' => 3, 'unit' => 'months', 'lead_days' => 7, 'assignee_id' => $tech->id,
            'checklist' => "Couper l'alimentation\nNettoyer les filtres\nVérifier l'écoulement"])->assertCreated()->assertJsonPath('data.frequency', 'Tous les 3 mois')->json('data');
        $this->assertSame(now()->addDays(7)->toDateString(), $p['next_due_on']);
        $this->assertSame(1, app(MaintenanceHooks::class)->generate($company));
        $this->assertSame(0, app(MaintenanceHooks::class)->generate($company)); // pas de doublon
        $prev = DB::table('mt_orders')->where('plan_id', $p['id'])->first();
        $this->assertSame('planned', $prev->status);
        $this->assertStringContainsString('Nettoyer les filtres', $prev->checklist);
        $tapi()->postJson('/api/maintenance/orders/'.$prev->id.'/finish', ['report' => 'RAS'])->assertOk();
        $this->assertSame(now()->addMonthsNoOverflow(3)->toDateString(), DB::table('mt_plans')->find($p['id'])->next_due_on);

        // plan par compteur : toutes les 250 h → OT quand le relevé approche (95 %)
        $mp = $api()->postJson('/api/maintenance/records/plans', ['title' => 'Vidange et filtres', 'equipment_id' => $ge['id'], 'trigger' => 'meter', 'every' => 250, 'unit' => 'months'])
            ->assertCreated()->assertJsonPath('data.unit', 'h')->json('data');
        $this->assertEquals(1445, $mp['next_meter']);
        $tapi()->postJson('/api/maintenance/records/readings', ['equipment_id' => $ge['id'], 'value' => 1300])->assertCreated();
        $this->assertFalse(DB::table('mt_orders')->where('plan_id', $mp['id'])->exists());
        $tapi()->postJson('/api/maintenance/records/readings', ['equipment_id' => $ge['id'], 'value' => 1435])->assertCreated();
        $vid = DB::table('mt_orders')->where('plan_id', $mp['id'])->first();
        $this->assertNotNull($vid);
        $this->assertSame($tech->id, (int) $vid->assignee_id); // technicien référent de l'équipement
        $api()->postJson('/api/maintenance/orders/'.$vid->id.'/finish', ['meter_reading' => 1440])->assertOk();
        $this->assertEquals(1690, DB::table('mt_plans')->find($mp['id'])->next_meter);

        // retard : rappel au technicien
        $late = $api()->postJson('/api/maintenance/records/orders', ['title' => 'Contrôle extincteurs', 'kind' => 'inspection', 'assignee_id' => $tech->id, 'planned_on' => now()->subDays(2)->toDateString()])
            ->assertCreated()->assertJsonPath('data.status', 'planned')->assertJsonPath('data.alert', 'En retard de 2 j')->json('data');
        $this->assertGreaterThanOrEqual(1, app(MaintenanceHooks::class)->alerts($company));
        $this->assertTrue(AppNotification::where('user_id', $tech->id)->where('title', 'Intervention en retard')->exists());

        $d = $api()->getJson('/api/maintenance/dashboard')->assertOk()->json('data');
        $this->assertSame(0, $d['down']);
        $this->assertSame(1, $d['late']);
        $this->assertEquals(3, $d['mttr']);
        $this->assertSame('Électrique', $d['causes'][0]['cause']);
        $api()->deleteJson('/api/maintenance/records/equipment/'.$ge['id'])->assertStatus(422);
        $api()->postJson('/api/maintenance/orders/'.$late['id'].'/cancel')->assertOk()->assertJsonPath('data.status', 'cancelled');
    }
}
