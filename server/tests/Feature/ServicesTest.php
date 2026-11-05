<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Flotte\FlotteHooks;
use App\Modules\Juridique\JuridiqueHooks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Espaces par service : marketing (calendrier éditorial, veille), juridique (contrats, échéances), parc automobile, dossier salarié. */
class ServicesTest extends TestCase
{
    protected function user($company, string $role, string $name): array
    {
        $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => \Illuminate\Support\Str::slug($name).'@svc.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $role)->value('id')]);

        return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
    }

    public function test_marketing_editorial_calendar_and_watch(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Comm SARL');
        $api = fn () => $this->api($token);
        $schema = $api()->getJson('/api/contenus/schema')->assertOk()->json('data');
        $this->assertArrayHasKey('posts', $schema['resources']);
        $this->assertSame('review', collect(collect($schema['resources']['posts']['fields'])->firstWhere('key', 'status')['options'])[2]['value']);
        $this->assertNotNull(Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Marketing & communication')->first());

        [$mk, $mapi] = $this->user($company, 'Marketing & communication', 'Mariam Marketing');
        $plan = $api()->postJson('/api/contenus/records/plans', ['title' => 'Lancement T2', 'status' => 'active', 'budget' => 500000, 'channels' => ['facebook', 'radio']])->assertCreated()->json('data');
        $this->assertSame(['facebook', 'radio'], $plan['channels']);
        $api()->postJson('/api/contenus/records/actions', ['title' => 'Spot radio', 'plan_id' => $plan['id'], 'spent' => 200000, 'sales' => 500000])->assertCreated()->assertJsonPath('data.roi', '150 %');
        $this->assertEquals(200000, $api()->getJson('/api/contenus/records/plans/'.$plan['id'])->json('data.spent'));

        $post = $mapi()->postJson('/api/contenus/records/posts', ['title' => 'Promo Tabaski', 'networks' => ['facebook', 'instagram'], 'plan_id' => $plan['id'],
            'scheduled_at' => now()->addDay()->setTime(10, 0)->toDateTimeString(), 'caption' => 'Jusqu\'à -20 %'])->assertCreated()->json('data');
        $this->assertSame('idea', $post['status']);
        $this->assertSame('Lancement T2', $post['plan_id_label']);
        $this->assertSame('Mariam Marketing', $post['owner_id_label']);
        // le marketing ne valide pas lui-même
        $mapi()->putJson('/api/contenus/records/posts/'.$post['id'], ['status' => 'approved'])->assertStatus(422);
        $mapi()->putJson('/api/contenus/records/posts/'.$post['id'], ['status' => 'review'])->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'Publication à valider')->exists());
        $api()->putJson('/api/contenus/records/posts/'.$post['id'], ['status' => 'approved'])->assertOk()->assertJsonPath('data.validator_id', $admin->id);
        $this->assertTrue(AppNotification::where('user_id', $mk->id)->where('title', 'Publication validée')->exists());
        // calendrier : filtre par réseau et période
        $this->assertCount(1, $api()->getJson('/api/contenus/records/posts?networks=instagram&from='.now()->toDateString().'&to='.now()->addDays(3)->toDateString())->json('data'));
        $this->assertCount(0, $api()->getJson('/api/contenus/records/posts?networks=tiktok')->json('data'));

        // veille : le commercial signale, le marketing lit
        [, $capi] = $this->user($company, 'Commercial', 'Koffi Commercial');
        $comp = $api()->postJson('/api/contenus/records/competitors', ['title' => 'Concurrent X', 'threat' => 'high'])->assertCreated()->json('data.id');
        $capi()->postJson('/api/contenus/records/observations', ['title' => 'Baisse de prix de 10 %', 'competitor_id' => $comp, 'kind' => 'price'])->assertCreated()->assertJsonPath('data.observed_on', now()->toDateString());
        $capi()->postJson('/api/contenus/records/posts', ['title' => 'X'])->assertForbidden();
        $this->assertSame(1, $api()->getJson('/api/contenus/records/competitors')->json('data.0.observations'));
        $d = $api()->getJson('/api/contenus/dashboard')->assertOk()->json('data');
        $this->assertSame(1, $d['threats']);
        $this->assertEquals(200000, $d['spent']);
        $api()->deleteJson('/api/contenus/records/observations/'.DB::table('mc_observations')->value('id'))->assertNoContent();
        $api()->postJson('/api/contenus/records/posts', ['title' => 'Y', 'networks' => ['myspace']])->assertStatus(422);
    }

    public function test_legal_contracts_templates_and_deadlines(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Droit SARL');
        $api = fn () => $this->api($token);
        $partner = $api()->postJson('/api/core/partners', ['name' => 'Fournisseur Béton', 'type' => 'supplier'])->assertCreated()->json('data.id');
        $templates = $api()->getJson('/api/juridique/records/templates')->assertOk()->json('data');
        $this->assertCount(5, $templates);

        $k = $api()->postJson('/api/juridique/records/contracts', ['title' => 'Fourniture de béton', 'kind' => 'supplier', 'status' => 'active', 'partner_id' => $partner,
            'start_date' => now()->subYear()->toDateString(), 'end_date' => now()->addDays(20)->toDateString(), 'notice_days' => 30, 'amount' => 12000000, 'periodicity' => 'yearly'])
            ->assertCreated()->json('data');
        $this->assertSame('Fournisseur Béton', $k['partner_id_label']);
        $this->assertStringStartsWith('Préavis dépassé', $k['alert']);
        $tpl = collect($templates)->firstWhere('kind', 'supplier');
        $body = $api()->postJson('/api/juridique/contracts/'.$k['id'].'/draft', ['template_id' => $tpl['id']])->assertOk()->json('data.body');
        $this->assertStringContainsString('Fournisseur Béton', $body);
        $this->assertStringContainsString('12 000 000 F CFA', $body);
        $this->assertStringContainsString('30 jours', $body);

        $case = $api()->postJson('/api/juridique/records/cases', ['title' => 'Impayé Client Z', 'kind' => 'recovery', 'amount_at_stake' => 3500000, 'risk' => 'high'])->assertCreated()->json('data.id');
        $api()->postJson('/api/juridique/records/deadlines', ['title' => 'Audience de conciliation', 'case_id' => $case, 'due_at' => now()->addDays(2)->setTime(9, 0)->toDateTimeString()])->assertCreated();
        $this->assertStringContainsString('Audience de conciliation', $api()->getJson('/api/juridique/records/cases/'.$case)->json('data.next_deadline'));

        $n = app(JuridiqueHooks::class)->alerts($company);
        $this->assertSame(2, $n); // contrat + audience
        $this->assertSame(0, app(JuridiqueHooks::class)->alerts($company)); // pas deux fois
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'Contrat à renouveler')->exists());

        // contrat échu sans reconduction → « échu » ; avec tacite reconduction → prolongé
        DB::table('jr_contracts')->where('id', $k['id'])->update(['end_date' => now()->subDay()->toDateString()]);
        $tacit = $api()->postJson('/api/juridique/records/contracts', ['title' => 'Bail siège', 'kind' => 'lease', 'status' => 'active', 'tacit_renewal' => true,
            'start_date' => now()->subYear()->subDays(2)->toDateString(), 'end_date' => now()->subDays(2)->toDateString()])->json('data.id');
        app(JuridiqueHooks::class)->alerts($company);
        $this->assertSame('expired', DB::table('jr_contracts')->where('id', $k['id'])->value('status'));
        $this->assertGreaterThan(now()->toDateString(), DB::table('jr_contracts')->where('id', $tacit)->value('end_date'));
        $d = $api()->getJson('/api/juridique/dashboard')->assertOk()->json('data');
        $this->assertSame(1, $d['open_cases']);
        $this->assertEquals(3500000, $d['at_stake']);

        [, $rapi] = $this->user($company, 'Commercial', 'Ali Com');
        $rapi()->getJson('/api/juridique/records/contracts')->assertForbidden();
    }

    public function test_fleet_requests_trips_fuel_and_alerts(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Parc SARL');
        $api = fn () => $this->api($token);
        [$com, $capi] = $this->user($company, 'Commercial', 'Awa Commerciale');
        [$com2, $capi2] = $this->user($company, 'Technicien', 'Yao Technicien');
        $hilux = $api()->postJson('/api/flotte/records/vehicles', ['title' => 'Hilux', 'plate' => '1234 AB 01', 'kind' => 'pickup', 'odometer' => 50000,
            'insurance_until' => now()->addDays(10)->toDateString()])->assertCreated()->json('data');
        $this->assertStringContainsString('Assurance', $hilux['alerts']);
        $capi()->postJson('/api/flotte/records/vehicles', ['title' => 'X'])->assertForbidden();

        $start = now()->addDay()->setTime(8, 0);
        $trip = $capi()->postJson('/api/flotte/records/trips', ['title' => 'Visite clients Yopougon', 'purpose' => 'customer', 'destination' => 'Yopougon', 'passengers' => 'Stagiaire',
            'planned_start' => $start->toDateTimeString(), 'planned_end' => $start->copy()->addHours(5)->toDateTimeString(), 'status' => 'out'])->assertCreated()->json('data');
        $this->assertSame('requested', $trip['status']);
        $this->assertStringStartsWith('SV', $trip['number']);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'Demande de véhicule')->exists());
        // le demandeur ne décide pas
        $capi()->postJson("/api/flotte/trips/{$trip['id']}/approve", ['vehicle_id' => $hilux['id']])->assertStatus(422);
        $api()->postJson("/api/flotte/trips/{$trip['id']}/approve", ['vehicle_id' => $hilux['id']])->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.vehicle_id_label', 'Hilux');
        $this->assertTrue(AppNotification::where('user_id', $com->id)->where('title', 'Sortie approuvée')->exists());

        // double réservation impossible
        $t2 = $capi2()->postJson('/api/flotte/records/trips', ['title' => 'Intervention', 'vehicle_id' => $hilux['id'],
            'planned_start' => $start->copy()->addHours(2)->toDateTimeString(), 'planned_end' => $start->copy()->addHours(7)->toDateTimeString()])->assertStatus(422);
        $this->assertStringContainsString('déjà réservé', $t2->json('message'));
        $avail = $capi2()->getJson('/api/flotte/availability?start='.urlencode($start->toDateTimeString()).'&end='.urlencode($start->copy()->addHours(3)->toDateTimeString()))->json('data');
        $this->assertFalse($avail[0]['free']);
        // chacun ne voit que ses demandes
        $this->assertCount(0, $capi2()->getJson('/api/flotte/records/trips')->json('data'));
        $this->assertCount(1, $api()->getJson('/api/flotte/records/trips')->json('data'));

        $capi()->postJson("/api/flotte/trips/{$trip['id']}/checkout", ['km' => 49000])->assertStatus(422);
        $capi()->postJson("/api/flotte/trips/{$trip['id']}/checkout", ['km' => 50010, 'at' => now()->subMinutes(40)->toIso8601String()])->assertOk()->assertJsonPath('data.status', 'out');
        $this->assertEqualsWithDelta(now()->subMinutes(40)->timestamp, strtotime(DB::table('fl_trips')->where('id', $trip['id'])->value('out_at')), 5); // saisie hors ligne : heure réelle gardée
        $this->assertSame('out', DB::table('fl_vehicles')->where('id', $hilux['id'])->value('status'));
        $back = $capi()->postJson("/api/flotte/trips/{$trip['id']}/return", ['km' => 50135, 'fuel_level' => 'half', 'report' => 'RAS', 'fuel_amount' => 20000, 'fuel_liters' => 25])->assertOk()->json('data');
        $this->assertSame(125, $back['distance']);
        $this->assertEquals(20000, $back['fuel_spent']);
        $this->assertSame(['available', 50135], [DB::table('fl_vehicles')->where('id', $hilux['id'])->value('status'), (int) DB::table('fl_vehicles')->where('id', $hilux['id'])->value('odometer')]);

        // pleins : consommation aux 100 km
        $api()->postJson('/api/flotte/records/fuel', ['vehicle_id' => $hilux['id'], 'amount' => 40000, 'liters' => 50, 'odometer' => 50200])->assertCreated();
        $f = $api()->postJson('/api/flotte/records/fuel', ['vehicle_id' => $hilux['id'], 'amount' => 32000, 'liters' => 40, 'odometer' => 50700])->assertCreated()->json('data');
        $this->assertSame('8,0', $f['consumption']);
        // l'assurance renouvelée met à jour la fiche
        $api()->postJson('/api/flotte/records/costs', ['title' => 'Assurance annuelle', 'vehicle_id' => $hilux['id'], 'kind' => 'insurance', 'amount' => 450000, 'next_date' => now()->addYear()->toDateString()])->assertCreated();
        $this->assertSame(now()->addYear()->toDateString(), DB::table('fl_vehicles')->where('id', $hilux['id'])->value('insurance_until'));
        $this->assertSame(50700, (int) DB::table('fl_vehicles')->where('id', $hilux['id'])->value('odometer'));

        // véhicule pas rentré → alerte
        $t3 = $api()->postJson('/api/flotte/records/trips', ['title' => 'Course', 'vehicle_id' => $hilux['id'], 'driver_id' => $com2->id,
            'planned_start' => now()->subHours(5)->toDateTimeString(), 'planned_end' => now()->subHours(2)->toDateTimeString()])->assertCreated()->json('data.id');
        $api()->postJson("/api/flotte/trips/$t3/approve", [])->assertOk();
        $capi2()->postJson("/api/flotte/trips/$t3/checkout", [])->assertOk();
        $this->assertGreaterThanOrEqual(1, app(FlotteHooks::class)->alerts($company));
        $this->assertTrue(AppNotification::where('user_id', $com2->id)->where('title', 'Véhicule pas encore rentré')->exists());
        $d = $api()->getJson('/api/flotte/dashboard')->assertOk()->json('data');
        $this->assertSame([1, 0, 125], [$d['out'], $d['available'], $d['km_month']]);
        $this->assertEquals(92000, $d['fuel_month']);
        $api()->deleteJson('/api/flotte/records/vehicles/'.$hilux['id'])->assertStatus(422);
    }

    public function test_employee_dossier(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Dossier SARL');
        $api = fn () => $this->api($token);
        $id = $this->uuid();
        $api()->postJson('/api/rh/employees', ['id' => $id, 'matricule' => 'M001', 'first_name' => 'Awa', 'last_name' => 'Koné', 'job_title' => 'Comptable',
            'hire_date' => now()->subYears(2)->toDateString(), 'base_salary' => 300000, 'contract_type' => 'CDI'])->assertSuccessful();
        $eid = DB::table('rh_employees')->where('matricule', 'M001')->value('id');
        $api()->postJson('/api/juridique/records/contracts', ['title' => 'CDI comptable', 'kind' => 'employment', 'employee_id' => (string) $eid, 'status' => 'active'])->assertCreated()
            ->assertJsonPath('data.employee_id_label', 'Awa Koné');
        $d = $api()->getJson("/api/rh/employees/$eid/dossier")->assertOk()->json();
        $this->assertSame('Awa', $d['employee']['first_name']);
        $this->assertSame(24, $d['seniority_months']);
        $this->assertCount(1, $d['contracts']);
        $this->assertArrayHasKey('salary_years', $d);
    }

    public function test_sales_force_targets_visits_and_tours(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Vente SARL');
        $api = fn () => $this->api($token);
        [$com, $capi] = $this->user($company, 'Commercial', 'Awa Vendeuse');
        [$com2, $capi2] = $this->user($company, 'Commercial', 'Yao Vendeur');
        $p1 = $api()->postJson('/api/core/partners', ['name' => 'Ancien Client', 'type' => 'customer'])->json('data.id');
        $p2 = $api()->postJson('/api/core/partners', ['name' => 'Nouveau Client', 'type' => 'customer'])->json('data.id');
        $inv = fn ($partner, $amount, $date) => $capi()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $partner, 'date' => $date, 'status' => 'validated',
            'lines' => [['id' => $this->uuid(), 'description' => 'Prestation', 'quantity' => 1, 'unit_price' => $amount, 'tax_rate' => 0]]])->assertCreated();
        $inv($p1, 100000, now()->subMonths(2)->toDateString());
        $inv($p1, 300000, now()->startOfMonth()->toDateString());
        $inv($p2, 200000, now()->startOfMonth()->toDateString());

        // objectif fixé par le responsable ; le commercial ne peut pas le modifier
        $capi()->postJson('/api/commercial/records/targets', ['user_id' => $com->id, 'period_start' => now()->toDateString(), 'revenue_target' => 1000000])->assertForbidden();
        $t = $api()->postJson('/api/commercial/records/targets', ['user_id' => $com->id, 'period_start' => now()->toDateString(), 'revenue_target' => 1000000, 'visits_target' => 10, 'customers_target' => 2])
            ->assertCreated()->json('data');
        $this->assertSame(now()->startOfMonth()->toDateString(), $t['period_start']);
        $this->assertNotNull(Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Responsable commercial')->first());
        $this->assertEquals(500000, $t['revenue_done']);
        $this->assertSame('50 %', $t['progress']);
        $this->assertSame(1, $t['performance']['customers']);
        $api()->postJson('/api/commercial/records/targets', ['user_id' => $com->id, 'period_start' => now()->startOfMonth()->addDays(2)->toDateString(), 'revenue_target' => 5])->assertStatus(422);

        // tournée, visites et compte rendu → relance CRM
        $day = now()->startOfMonth()->addDays(2);
        $tour = $capi()->postJson('/api/commercial/records/tours', ['title' => 'Zone 4', 'zone' => 'Marcory Zone 4', 'date' => $day->toDateString()])->assertCreated()->json('data');
        $capi()->postJson('/api/commercial/records/visits', ['title' => 'Présentation', 'planned_at' => $day->copy()->setTime(10, 0)->toDateTimeString()])->assertStatus(422);
        $v = $capi()->postJson('/api/commercial/records/visits', ['title' => 'Présentation offre', 'partner_id' => $p2, 'tour_id' => $tour['id'], 'planned_at' => $day->copy()->setTime(10, 0)->toDateTimeString()])
            ->assertCreated()->json('data');
        $capi()->postJson('/api/commercial/records/visits', ['title' => 'Prospection', 'prospect' => 'Boutique Mode', 'tour_id' => $tour['id'], 'planned_at' => $day->copy()->setTime(11, 0)->toDateTimeString()])->assertCreated();
        $done = $capi()->putJson('/api/commercial/records/visits/'.$v['id'], ['outcome' => 'quote', 'amount' => 750000, 'report' => 'Veut 3 bornes Wi-Fi', 'next_step' => 'Envoyer le devis', 'next_date' => now()->addDays(3)->toDateString()])
            ->assertOk()->json('data');
        $this->assertSame('done', $done['status']);
        $this->assertNotNull($done['follow_up_id']);
        $this->assertSame('Envoyer le devis — Nouveau Client', DB::table('crm_activities')->where('id', $done['follow_up_id'])->value('subject'));
        $this->assertSame('1 / 2', $capi()->getJson('/api/commercial/records/tours/'.$tour['id'])->json('data.visits'));
        // chacun ne voit que son terrain
        $this->assertCount(0, $capi2()->getJson('/api/commercial/records/visits')->json('data'));
        $capi2()->putJson('/api/commercial/records/visits/'.$v['id'], ['report' => 'x'])->assertNotFound();
        $capi2()->getJson('/api/commercial/records/visits/'.$v['id'])->assertNotFound(); // fiche d'un autre commercial : invisible aussi en détail

        // véhicule demandé au parc pour la tournée
        $api()->postJson('/api/flotte/records/vehicles', ['title' => 'Hilux'])->assertCreated();
        $trip = $capi()->postJson("/api/commercial/tours/{$tour['id']}/vehicle", ['start' => '08:00', 'end' => '13:00'])->assertOk()->json('data');
        $this->assertSame('requested', $trip['status']);
        $this->assertStringContainsString('Boutique Mode', DB::table('fl_trips')->where('id', $trip['trip_id'])->value('notes'));
        $this->assertSame($com->id, (int) DB::table('fl_trips')->where('id', $trip['trip_id'])->value('requester_id'));
        $capi()->postJson("/api/commercial/tours/{$tour['id']}/vehicle", [])->assertStatus(422);
        $this->assertStringContainsString('Demandée', $capi()->getJson('/api/commercial/records/tours/'.$tour['id'])->json('data.vehicle'));

        $d = $api()->getJson('/api/commercial/dashboard')->assertOk()->json('data');
        $row = collect($d['team'])->firstWhere('user_id', $com->id);
        $this->assertEquals([500000, 50, 1], [$row['revenue'], $row['progress'], $row['visits']]);
        $this->assertCount(1, $capi2()->getJson('/api/commercial/dashboard')->json('data.team'));
    }

    public function test_dev_backlog_sprints_and_releases(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Dev SARL');
        $api = fn () => $this->api($token);
        $app = $api()->postJson('/api/dev/records/apps', ['title' => 'Yomara ERP', 'stack' => 'Laravel, Flutter'])->assertCreated()->json('data.id');
        $this->assertNotNull(Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Développeur')->first());
        [$dev, $dapi] = $this->user($company, 'Développeur', 'Kader Dev');
        [, $dapi2] = $this->user($company, 'Développeur', 'Ines Dev');
        $sprint = $api()->postJson('/api/dev/records/sprints', ['title' => 'Sprint 1', 'app_id' => $app, 'status' => 'active', 'start_date' => now()->startOfWeek()->toDateString()])->assertCreated()->json('data.id');
        $rel = $api()->postJson('/api/dev/records/releases', ['title' => '1.2.0', 'app_id' => $app])->assertCreated()->json('data.id');
        $dapi()->postJson('/api/dev/records/sprints', ['title' => 'X'])->assertForbidden();
        $a = $api()->postJson('/api/dev/records/items', ['title' => 'Export Excel des factures', 'kind' => 'story', 'app_id' => $app, 'sprint_id' => $sprint, 'release_id' => $rel, 'points' => 5, 'assignee_id' => $dev->id])
            ->assertCreated()->json('data');
        $this->assertStringStartsWith('#', $a['number']);
        $this->assertTrue(AppNotification::where('user_id', $dev->id)->where('title', 'Tâche qui vous est assignée')->exists());
        $b = $dapi()->postJson('/api/dev/records/items', ['title' => 'Plantage à l\'impression', 'kind' => 'bug', 'priority' => 'critical', 'sprint_id' => $sprint, 'release_id' => $rel, 'points' => 3])->assertCreated()->json('data');
        $dapi2()->putJson('/api/dev/records/items/'.$a['id'], ['status' => 'done'])->assertStatus(422);
        $dapi()->putJson('/api/dev/records/items/'.$a['id'], ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $done = $dapi()->putJson('/api/dev/records/items/'.$a['id'], ['status' => 'done'])->assertOk()->json('data');
        $this->assertNotNull($done['done_at']);
        $dapi()->putJson('/api/dev/records/items/'.$b['id'], ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.assignee_id', $dev->id);
        $this->assertSame('5 / 8 pts (63 %)', $api()->getJson('/api/dev/records/sprints/'.$sprint)->json('data.progress'));
        $dapi()->postJson("/api/dev/releases/$rel/changelog")->assertForbidden();
        $notes = $api()->postJson("/api/dev/releases/$rel/changelog")->assertOk()->json('data.notes');
        $this->assertStringContainsString("Nouveautés :\n• Export Excel des factures", $notes);
        $this->assertStringNotContainsString('Plantage', $notes);
        $d = $dapi()->getJson('/api/dev/dashboard')->assertOk()->json('data');
        $this->assertSame([1, 1, 1], [$d['mine'], $d['bugs'], $d['critical']]);
    }

    public function test_activity_report_written_from_activities_and_sent_to_supervisor(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Rapport SARL');
        $api = fn () => $this->api($token);
        [$com, $capi] = $this->user($company, 'Commercial', 'Awa Rapport');
        $partner = $api()->postJson('/api/core/partners', ['name' => 'Client Rapport', 'type' => 'customer'])->json('data.id');
        $capi()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $partner, 'date' => now()->toDateString(), 'status' => 'validated',
            'lines' => [['id' => $this->uuid(), 'description' => 'Prestation', 'quantity' => 1, 'unit_price' => 250000, 'tax_rate' => 0]]])->assertCreated();
        $visit = $capi()->postJson('/api/commercial/records/visits', ['title' => 'Présentation', 'partner_id' => $partner, 'planned_at' => now()->setTime(10, 0)->toDateTimeString(),
            'outcome' => 'quote', 'report' => 'Intéressé par 3 bornes'])->assertCreated()->json('data');
        $other = $capi()->postJson('/api/commercial/records/visits', ['title' => 'Visite perso', 'prospect' => 'X', 'planned_at' => now()->setTime(15, 0)->toDateTimeString(), 'status' => 'done'])->json('data');
        // « Ajouter au rapport » sur la visite : remplace l'élément automatique, avec le commentaire
        $capi()->postJson('/api/activite/entries', ['title' => 'Visite chez Client Rapport : présentation de l\'offre', 'details' => 'Devis à envoyer lundi', 'minutes' => 90,
            'source_module' => 'commercial', 'source_type' => 'visits', 'source_id' => (string) $visit['id']])->assertCreated()->assertJsonPath('data.report_already_sent', false);
        $capi()->postJson('/api/activite/entries', ['title' => 'Formation produit chez le fournisseur', 'minutes' => 180])->assertCreated();

        $rep = $capi()->getJson('/api/activite/report')->assertOk()->json('data');
        [$mon, $fri] = \App\Modules\Activite\ReportService::week();
        $this->assertSame([$mon, $fri], [$rep['period_start'], $rep['period_end']]);
        $this->assertNull($rep['id']);
        $titles = collect($rep['items'])->pluck('title');
        $this->assertTrue($titles->contains(fn ($t) => str_starts_with($t, 'Facture') && str_contains($t, 'Client Rapport')));
        $this->assertTrue($titles->contains('Visite chez Client Rapport : présentation de l\'offre'));
        $this->assertFalse($titles->contains(fn ($t) => str_contains($t, 'Visite — Client Rapport')));
        $this->assertTrue($titles->contains('Formation produit chez le fournisseur'));
        $this->assertSame(270, $rep['stats']['minutes']);

        // relecture : on retire une ligne, on écrit la synthèse
        $saved = $capi()->postJson('/api/activite/report', ['summary' => 'Bonne semaine : une facture et un devis en préparation.', 'excluded' => ['commercial.visits:'.$other['id']]])->assertOk()->json('data');
        $this->assertNotNull($saved['id']);
        $this->assertFalse(collect($saved['items'])->firstWhere('key', 'commercial.visits:'.$other['id'])['included']);
        $pdf = $capi()->get('/api/activite/reports/'.$saved['id'].'/file/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->assertStringContainsString('Formation produit', $capi()->get('/api/activite/reports/'.$saved['id'].'/file/word')->assertOk()->getContent());

        // envoi au supérieur, validation
        $capi()->postJson('/api/activite/reports/'.$saved['id'].'/send', ['supervisor_id' => $com->id])->assertStatus(422);
        $sent = $capi()->postJson('/api/activite/reports/'.$saved['id'].'/send', ['supervisor_id' => $admin->id, 'message' => 'Bonne lecture'])->assertOk()->json('data');
        $this->assertSame('sent', $sent['status']);
        $this->assertFalse(collect($sent['items'])->contains('key', 'commercial.visits:'.$other['id']));
        $this->assertSame($admin->id, (int) DB::table('users')->where('id', $com->id)->value('supervisor_id'));
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'Rapport d\'activité reçu')->exists());
        $capi()->postJson('/api/activite/report', ['summary' => 'x'])->assertStatus(422);
        $this->assertCount(1, $api()->getJson('/api/activite/reports?box=team')->json('data'));
        $capi()->postJson('/api/activite/reports/'.$saved['id'].'/review', ['decision' => 'approved'])->assertStatus(422);
        $api()->postJson('/api/activite/reports/'.$saved['id'].'/review', ['decision' => 'returned'])->assertStatus(422);
        $api()->postJson('/api/activite/reports/'.$saved['id'].'/review', ['decision' => 'returned', 'comment' => 'Précise le montant du devis'])->assertOk()->assertJsonPath('data.status', 'returned');
        $capi()->postJson('/api/activite/report', ['difficulties' => 'Client difficile à joindre'])->assertOk();
        $capi()->postJson('/api/activite/reports/'.$saved['id'].'/send', ['supervisor_id' => $admin->id])->assertOk();
        $api()->postJson('/api/activite/reports/'.$saved['id'].'/review', ['decision' => 'approved', 'comment' => 'Bravo'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue(AppNotification::where('user_id', $com->id)->where('title', 'Rapport d\'activité validé')->exists());

        // brouillon automatique du vendredi pour les autres
        [$mk, $mapi] = $this->user($company, 'Commercial', 'Koffi Auto');
        $mapi()->postJson('/api/activite/entries', ['title' => 'Prospection téléphonique'])->assertCreated();
        $this->assertSame(1, app(\App\Modules\Activite\ReportService::class)->weekly($company));
        $this->assertTrue(AppNotification::where('user_id', $mk->id)->where('title', 'Votre rapport de la semaine est prêt')->exists());
    }
}
