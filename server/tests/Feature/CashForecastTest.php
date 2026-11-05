<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashForecastTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_thirteen_week_forecast_scenarios_threshold_alert_and_project_expenses(): void
    {
        Carbon::setTestNow('2026-11-04 10:00:00'); // mercredi : semaine 0 = lundi 2 novembre
        [$company, $admin, $token] = $this->makeCompany('Négoce Bassam');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null, $client = null) => ($client ?? $api)()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        $ok = function (array $r) {
            $this->assertSame('applied', $r['status'], json_encode($r));

            return $r['record'];
        };
        $partner = fn (string $name, string $type = 'customer') => $api()->postJson('/api/core/partners', ['name' => $name, 'type' => $type])->json('data.id');
        [$a, $b, $c, $sup] = [$partner('Client Retardataire'), $partner('Client Ancien'), $partner('Client Commande'), $partner('Fournisseur Ciment', 'supplier')];
        $doc = function (string $module, string $type, string $partner, float $ht, string $date, ?string $due = null) use ($api) {
            $d = $api()->postJson("/api/$module/documents", ['type' => $type, 'partner_id' => $partner, 'date' => $date, 'due_date' => $due,
                'lines' => [['description' => 'Article', 'quantity' => 1, 'unit_price' => $ht, 'tax_rate' => 18]]])->assertCreated()->json('data');

            return $api()->postJson("/api/$module/documents/{$d['id']}/validate")->assertOk()->json('data');
        };

        // Historique : le client A a payé avec 20 jours de retard
        $old = $doc('ventes', 'invoice', $a, 500000, '2026-08-01', '2026-08-31');
        $api()->postJson('/api/ventes/payments', ['document_id' => $old['id'], 'date' => '2026-09-20', 'amount' => 590000, 'method' => 'bank_transfer'])->assertCreated();
        // Créances : A à échéance le 10/11, B très ancienne (douteuse en pessimiste)
        $doc('ventes', 'invoice', $a, 500000, '2026-10-11', '2026-11-10');
        $doc('ventes', 'invoice', $b, 100000, '2026-06-01', '2026-07-01');
        // Commande client à facturer, facture fournisseur, bon de commande
        $doc('ventes', 'order', $c, 1000000, '2026-11-04');
        $doc('achats', 'supplier_invoice', $sup, 200000, '2026-10-21', '2026-11-20');
        $doc('achats', 'purchase_order', $sup, 300000, '2026-11-04');
        // Abonnement mensuel (prochaine facture le 15/11), opportunité CRM à 40 %
        $ok($push('ab_subscriptions', ['partner_id' => $c, 'name' => 'Maintenance', 'start_date' => '2026-10-15', 'next_date' => '2026-11-15', 'interval_unit' => 'month', 'interval_count' => 1,
            'lines' => [['description' => 'Maintenance', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 18]]]));
        $stage = DB::table('crm_stages')->where('company_id', $company->id)->where('kind', 'open')->orderBy('sequence')->value('id');
        $ok($push('crm_leads', ['title' => 'Entrepôt San Pedro', 'stage_id' => $stage, 'expected_revenue' => 1000000, 'probability' => 40, 'expected_close_date' => '2026-11-20']));
        // Loyer mensuel ; subvention retardée seulement en pessimiste
        $api()->postJson('/api/tresorerie/forecasts', ['label' => 'Loyer', 'amount' => -300000, 'date' => '2026-12-01', 'repeat' => 'monthly'])->assertCreated();
        $api()->postJson('/api/tresorerie/forecasts', ['label' => 'Pénalité de retard', 'amount' => -100000, 'date' => '2026-12-15', 'repeat' => 'none', 'scenarios' => ['pessimistic']])->assertCreated();
        $api()->postJson('/api/tresorerie/forecasts', ['label' => 'x', 'amount' => -1, 'date' => '2026-12-15', 'scenarios' => ['demain']])->assertStatus(422);
        // Paie d'octobre payée le 30 : nets 800 000, coût total 1 100 000
        $run = DB::table('paie_runs')->insertGetId(['company_id' => $company->id, 'period' => '2026-10', 'label' => 'Octobre 2026', 'status' => 'paid', 'payment_date' => '2026-10-30', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('paie_payslips')->insert(['company_id' => $company->id, 'run_id' => $run, 'employee_id' => 1, 'employee' => '{}', 'result' => '{}', 'net' => 800000, 'cost' => 1100000, 'gross' => 900000, 'created_at' => now(), 'updated_at' => now()]);

        // Note de frais approuvée, rattachée à un projet
        $pid = $this->uuid();
        $ok($push('prj_projects', ['name' => 'Chantier Bassam', 'budget_amount' => 100000], $pid));
        $emp = $this->uuid();
        $ok($push('rh_employees', ['first_name' => 'Kofi', 'last_name' => 'Yao', 'base_salary' => 200000, 'hire_date' => '2025-01-01'], $emp));
        $x = $this->uuid();
        $this->assertSame('rejected', $push('nf_expenses', ['employee_id' => $emp, 'date' => '2026-11-03', 'amount' => 25000, 'description' => 'Carburant chantier', 'project_id' => $this->uuid()], $x)['status']);
        $taxi = DB::table('nf_categories')->where('company_id', $company->id)->where('name', 'like', 'Transport%')->value('id');
        $ok($push('nf_expenses', ['employee_id' => $emp, 'category_id' => $taxi, 'date' => '2026-11-03', 'amount' => 25000, 'description' => 'Carburant chantier', 'project_id' => $pid, 'status' => 'submitted'], $x));
        $paul = User::create(['company_id' => $company->id, 'name' => 'Paul', 'email' => 'paul@bassam.ci', 'password' => 'secret123', 'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Comptable')->value('id')]);
        $ok($push('nf_expenses', ['status' => 'approved'], $x, fn () => $this->api($paul->createToken('p')->plainTextToken)));
        $costs = $api()->getJson("/api/projets/projects/$pid/costs")->assertOk()->json('data');
        $this->assertEquals([25000, 25000, 25.0], [$costs['expenses'], $costs['actual_cost'], $costs['budget_used']]);
        $this->assertSame('Carburant chantier', $costs['expense_list'][0]['description']);

        // ---------------------------------------------------------------- prévision (TVA testée à part)
        $api()->putJson('/api/tresorerie/forecast-settings', ['vat_enabled' => false])->assertOk();
        $f = $api()->getJson('/api/tresorerie/forecast')->assertOk()->json('data');
        $this->assertSame(13, $f['weeks_count']);
        $this->assertCount(13, $f['weeks']);
        $this->assertSame('2026-11-02', $f['weeks'][0]['week']);
        $this->assertEquals(['clients' => 1, 'average' => 20], $f['customer_delays']);
        $cat = fn (array $w, string $k) => (float) ($w['categories'][$k] ?? 0);
        $W = $f['weeks'];
        // Réaliste
        $this->assertEquals([118000, -25000], [$cat($W[0], 'clients'), $cat($W[0], 'notes_frais')]);
        $this->assertEquals(-300000, $cat($W[1], 'charges')); // charges d'octobre le 15/11
        $this->assertEquals(-236000, $cat($W[2], 'fournisseurs'));
        $this->assertEquals([590000, -800000, -300000], [$cat($W[4], 'clients'), $cat($W[4], 'salaires'), $cat($W[4], 'previsions')]); // A paie le 30/11 (+20 j)
        $this->assertEquals(1180000, $cat($W[5], 'commandes')); // facturée le 11/11, payée à 30 jours
        $this->assertEquals([-300000, 118000, -354000], [$cat($W[6], 'charges'), $cat($W[6], 'abonnements'), $cat($W[6], 'achats')]);
        $this->assertEquals(0, $cat($W[7], 'pipeline'));
        $this->assertEquals([-800000, -300000], [$cat($W[8], 'salaires'), $cat($W[8], 'previsions')]);
        $this->assertEquals([-300000, 118000], [$cat($W[10], 'charges'), $cat($W[10], 'abonnements')]);
        $this->assertEquals(-800000, $cat($W[12], 'salaires'));
        $sc = $f['scenarios'];
        $this->assertEquals([2124000, -4515000], [$sc['realistic']['in'], $sc['realistic']['out']]);
        $this->assertEquals([2524000, -4515000], [$sc['optimistic']['in'], $sc['optimistic']['out']]); // + opportunité 40 % × 1 000 000
        $this->assertEquals([1534000, -4615000], [$sc['pessimistic']['in'], $sc['pessimistic']['out']]); // créance douteuse écartée, commande à 70 %, retards
        $O = $f['scenario_weeks']['optimistic'];
        $P = $f['scenario_weeks']['pessimistic'];
        $this->assertEquals([590000, 400000], [$cat($O[1], 'clients'), $cat($O[7], 'pipeline')]);
        $this->assertEquals([0, 590000, 826000, -100000], [$cat($P[0], 'clients'), $cat($P[8], 'clients'), $cat($P[9], 'commandes'), $cat($P[6], 'previsions')]);
        // Soldes cumulés cohérents
        $run = $f['start_balance'];
        foreach ($W as $i => $w) {
            $run += $w['in'] + $w['out'];
            $this->assertEqualsWithDelta($run, $w['balance'], 0.01);
            $this->assertEqualsWithDelta($w['balance'], $sc['realistic']['balances'][$i], 0.01);
        }
        $this->assertEqualsWithDelta($f['start_balance'] + 2524000 - 4515000, $sc['optimistic']['end'], 0.01);
        $this->assertEquals(min($sc['realistic']['balances']), $f['lowest']);

        // ---------------------------------------------------------------- seuil et alerte
        $s = $api()->putJson('/api/tresorerie/forecast-settings', ['threshold' => $f['start_balance'] + 1000000, 'orders_pessimistic' => 50])->assertOk()->json('data');
        $this->assertEquals([50, 30, 13], [$s['orders_pessimistic'], $s['pessimistic_extra_days'], $s['weeks']]);
        $f = $api()->getJson('/api/tresorerie/forecast')->assertOk()->json('data');
        $this->assertSame('2026-11-02', $f['first_below']);
        $this->assertEquals(590000, $cat($f['scenario_weeks']['pessimistic'][9], 'commandes')); // 50 % de 1 180 000
        $this->artisan('erp:tresorerie-prevision')->assertSuccessful();
        $this->artisan('erp:tresorerie-prevision')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Trésorerie prévue sous le seuil%')->count());
        $api()->putJson('/api/tresorerie/forecast-settings', ['threshold' => -100000000])->assertOk();
        $this->assertNull($api()->getJson('/api/tresorerie/forecast')->json('data.first_below'));
        $this->artisan('erp:tresorerie-prevision')->assertSuccessful();
        $this->assertNull(data_get($company->fresh()->settings, '_forecast_alert'));
        $api()->putJson('/api/tresorerie/forecast-settings', ['weeks' => 2])->assertStatus(422);
        $this->assertCount(26, $api()->getJson('/api/tresorerie/forecast?weeks=40')->json('data.weeks'));
    }
}
