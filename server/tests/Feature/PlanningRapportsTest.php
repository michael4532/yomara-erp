<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Planning\Models\Assignment;
use App\Modules\Prestations\Models\Entry;
use App\Modules\Prestations\Models\Pack;
use App\Modules\Prestations\Models\Report;
use App\Modules\Prestations\ReportService;
use App\Modules\Projets\Models\Timesheet;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlanningRapportsTest extends TestCase
{
    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Studio Digital');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null, ?callable $as = null) => ($as ?? $api)()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        $client = fn (string $name, array $extra = []) => $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'customer'] + $extra)->json('data.id');

        return compact('company', 'admin', 'api', 'push', 'client', 'token');
    }

    private function ok(array $r): array
    {
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $r['record'];
    }

    public function test_project_time_is_counted_on_the_hour_pack(): void
    {
        ['api' => $api, 'push' => $push, 'client' => $client, 'company' => $company] = $this->env();
        $acme = $client('Hôtel Ivoire');
        $other = $client('Autre client');
        $pack = $this->ok($push('pr_packs', ['partner_id' => $acme, 'name' => 'Maintenance 10 h', 'hours' => 10, 'price' => 250000, 'hourly_rate' => 30000, 'start_date' => now()->subDays(10)->toDateString()]));
        $prj = $this->uuid();
        // Forfait d'un autre client refusé
        $this->assertStringContainsString('client du projet', $push('prj_projects', ['name' => 'Refonte site', 'partner_id' => $other, 'billing_mode' => 'time', 'hourly_rate' => 25000, 'pack_id' => $pack['id']], $prj)['message']);
        $this->ok($push('prj_projects', ['name' => 'Refonte site', 'partner_id' => $acme, 'billing_mode' => 'time', 'hourly_rate' => 25000], $prj));
        $t1 = $this->uuid();
        $this->ok($push('prj_timesheets', ['project_id' => $prj, 'date' => now()->subDays(3)->toDateString(), 'hours' => 3, 'description' => 'Maquettes', 'billable' => true], $t1));
        $this->assertSame(0, Entry::count());

        // Rattachement : le temps non facturé passe sur le forfait
        $this->ok($push('prj_projects', ['pack_id' => $pack['id']], $prj));
        $e = Entry::where('timesheet_id', $t1)->first();
        $this->assertEquals([3.0, 'Refonte site', 'Maquettes'], [(float) $e->hours, $e->project_name, $e->description]);
        $this->assertSame($pack['id'], Timesheet::find($t1)->pack_id);
        // Temps non facturable : pas décompté ; modification et suppression suivies
        $t2 = $this->uuid();
        $this->ok($push('prj_timesheets', ['project_id' => $prj, 'date' => now()->subDays(2)->toDateString(), 'hours' => 2, 'billable' => false], $t2));
        $t3 = $this->uuid();
        $this->ok($push('prj_timesheets', ['project_id' => $prj, 'date' => now()->subDays(1)->toDateString(), 'hours' => 6, 'description' => 'Intégration', 'billable' => true], $t3));
        $this->assertEquals(9.0, Pack::find($pack['id'])->consumed());
        $this->ok($push('prj_timesheets', ['hours' => 8.5], $t3));
        $this->assertEquals(11.5, Pack::find($pack['id'])->consumed());
        $p = Pack::find($pack['id']);
        $this->assertSame('exhausted', $p->status);
        $this->assertEquals(1.5, $p->toSyncArray()['overage_unbilled']);
        // Le temps miroir ne se modifie pas depuis le forfait
        $this->assertStringContainsString('modifiez-le dans le projet', $push('pr_entries', ['hours' => 1], $e->id)['message']);
        $this->assertSame('rejected', $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'pr_entries', 'op' => 'delete', 'record_id' => $e->id]]])->json('results.0.status'));

        // Le projet ne refacture pas ce temps
        $stats = collect($api()->getJson('/api/projets/stats')->assertOk()->json('projects'))->firstWhere('id', $prj);
        $this->assertEquals([0, 11.5], [$stats['to_invoice_hours'], $stats['pack_hours']]);
        $api()->postJson('/api/projets/projects/'.$prj.'/invoice', [])->assertStatus(422)->assertJsonPath('message', 'Aucun temps facturable en attente.');

        // Suppression du temps → retiré du forfait
        $this->assertSame('applied', $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'prj_timesheets', 'op' => 'delete', 'record_id' => $t1]]])->json('results.0.status'));
        $this->assertEquals(8.5, Pack::find($pack['id'])->consumed());
        $this->assertNull(Entry::where('timesheet_id', $t1)->first());

        // Détachement : le temps redevient facturable par le projet
        $this->ok($push('prj_projects', ['pack_id' => null], $prj));
        $this->assertEquals(0.0, Pack::find($pack['id'])->consumed());
        $this->assertNull(Timesheet::find($t3)->pack_id);
        $stats = collect($api()->getJson('/api/projets/stats')->json('projects'))->firstWhere('id', $prj);
        $this->assertEquals(8.5, $stats['to_invoice_hours']);
        $this->assertSame('active', Pack::find($pack['id'])->status);
    }

    public function test_monthly_activity_reports(): void
    {
        Storage::fake('local');
        ['api' => $api, 'push' => $push, 'client' => $client, 'company' => $company] = $this->env();
        $acme = $client('Restaurant Le Baobab', ['email' => 'baobab@test.ci']);
        $quiet = $client('Client sans activité', ['email' => 'quiet@test.ci']);
        $last = now()->subMonthNoOverflow()->startOfMonth();
        $pack = $this->ok($push('pr_packs', ['partner_id' => $acme, 'name' => 'Community management 20 h', 'hours' => 20, 'start_date' => $last->toDateString()]));
        $this->ok($push('pr_entries', ['pack_id' => $pack['id'], 'date' => $last->copy()->addDays(4)->toDateString(), 'hours' => 6, 'description' => 'Publications semaine 1']));
        $this->ok($push('pr_entries', ['pack_id' => $pack['id'], 'date' => $last->copy()->addDays(12)->toDateString(), 'hours' => 5.5, 'description' => 'Visuels Tabaski']));
        $this->ok($push('pr_entries', ['pack_id' => $pack['id'], 'date' => now()->toDateString(), 'hours' => 2, 'description' => 'Ce mois-ci']));
        $prj = $this->uuid();
        $this->ok($push('prj_projects', ['name' => 'Site vitrine', 'partner_id' => $acme, 'billing_mode' => 'time', 'hourly_rate' => 20000], $prj));
        $this->ok($push('prj_timesheets', ['project_id' => $prj, 'date' => $last->copy()->addDays(8)->toDateString(), 'hours' => 4, 'description' => 'Intégration WordPress', 'billable' => true]));
        $d = $this->ok($push('pr_deliverables', ['partner_id' => $acme, 'title' => 'Calendrier éditorial', 'url' => 'https://example.com/cal', 'status' => 'sent']));
        \App\Modules\Prestations\Models\Deliverable::find($d['id'])->forceFill(['sent_at' => $last->copy()->addDays(20)])->save();

        $data = app(ReportService::class)->data($company, $acme, $last->format('Y-m'));
        $this->assertEquals(15.5, $data['hours']);
        $this->assertCount(3, $data['time']);
        $this->assertEquals([11.5, 11.5, 8.5], [$data['packs'][0]['month'], $data['packs'][0]['consumed'], $data['packs'][0]['remaining']]);
        $this->assertSame('Calendrier éditorial', $data['deliverables'][0]['title']);

        // Génération manuelle + PDF
        $r = $api()->postJson('/api/prestations/reports', ['partner_id' => $acme, 'period' => $last->format('Y-m')])->assertCreated()->json('data');
        $this->assertEquals([15.5, 1], [$r['hours'], $r['deliverables']]);
        $this->assertArrayNotHasKey('token', $r);
        $pdf = $api()->get('/api/prestations/reports/'.$r['id'].'/pdf')->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $rep = Report::find($r['id']);
        $this->get('/rapport/'.$rep->token)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get('/rapport/'.str_repeat('x', 40))->assertNotFound();
        $api()->postJson('/api/prestations/reports', ['partner_id' => $acme, 'period' => now()->addMonth()->format('Y-m')])->assertStatus(422);
        // Régénéré au lieu d'être dupliqué ; les appareils ne créent pas de rapport
        $api()->postJson('/api/prestations/reports', ['partner_id' => $acme, 'period' => $last->format('Y-m')])->assertCreated();
        $this->assertSame(1, Report::count());
        $this->assertSame('rejected', $push('pr_reports', ['period' => '2026-01'])['status']);

        // Envoi automatique : désactivé par défaut, puis un rapport par client actif, une seule fois
        Report::query()->forceDelete();
        app()->instance('tenant', $company->fresh());
        $this->assertSame(0, app(ReportService::class)->runMonthly($company->fresh()));
        $api()->putJson('/api/prestations/settings', ['monthly_reports' => true])->assertOk()->assertJsonPath('data.monthly_reports', true);
        app()->instance('tenant', $company->fresh());
        $this->assertSame(1, app(ReportService::class)->runMonthly($company->fresh()));
        $this->assertSame(0, app(ReportService::class)->runMonthly($company->fresh()));
        $this->assertSame([$acme], Report::pluck('partner_id')->all());
        $this->assertNotContains($quiet, Report::pluck('partner_id')->all());

        // Espace client
        $url = $api()->postJson("/api/portail/partners/$acme/invite", ['channel' => 'none'])->json('url');
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get(parse_url($url, PHP_URL_PATH).'/prestations')->assertOk()->assertSee('Rapports d', false)->assertSee(ucfirst(Report::first()->label()));
        // Projets exclus sur demande
        $api()->putJson('/api/prestations/settings', ['include_projects' => false])->assertOk();
        $this->assertEquals(11.5, app(ReportService::class)->data($company->fresh(), $acme, $last->format('Y-m'))['hours']);
    }

    public function test_resource_planning_capacity_load_and_availability(): void
    {
        ['api' => $api, 'push' => $push, 'client' => $client, 'company' => $company, 'admin' => $admin] = $this->env();
        $role = fn ($n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $awa = User::create(['company_id' => $company->id, 'name' => 'Awa Graphiste', 'email' => 'awa@studio.ci', 'password' => 'secret123', 'role_id' => $role('Salarié')]);
        $kofi = User::create(['company_id' => $company->id, 'name' => 'Kofi Dev', 'email' => 'kofi@studio.ci', 'password' => 'secret123', 'role_id' => $role('Salarié')]);
        $awaToken = $awa->createToken('t')->plainTextToken;
        $monday = Carbon::parse('2027-03-01'); // lundi, sans jour férié
        $fri = $monday->copy()->addDays(4);
        $acme = $client('Banque Atlantique');
        $prj = $this->uuid();
        $this->ok($push('prj_projects', ['name' => 'Application mobile', 'partner_id' => $acme, 'color' => '#8E44AD'], $prj));

        $a1 = $this->ok($push('pl_assignments', ['user_id' => $kofi->id, 'project_id' => $prj, 'start_date' => $monday->toDateString(), 'end_date' => $fri->toDateString(), 'hours_per_day' => 6]));
        $this->assertEquals(['Application mobile', 'Banque Atlantique', '#8E44AD', 'Kofi Dev'], [$a1['title'], $a1['partner_name'], $a1['color'], $a1['user_name']]);
        $this->ok($push('pl_assignments', ['user_id' => $kofi->id, 'title' => 'Support client', 'start_date' => $monday->copy()->addDays(3)->toDateString(), 'end_date' => $fri->toDateString(), 'hours_per_day' => 4, 'status' => 'tentative']));
        $this->assertStringContainsString('précède', $push('pl_assignments', ['user_id' => $kofi->id, 'title' => 'X', 'start_date' => $fri->toDateString(), 'end_date' => $monday->toDateString(), 'hours_per_day' => 2])['message']);
        $this->assertStringContainsString('intitulé', $push('pl_assignments', ['user_id' => $kofi->id, 'start_date' => $monday->toDateString(), 'end_date' => $monday->toDateString(), 'hours_per_day' => 2])['message']);
        // Un salarié consulte mais n'affecte pas
        $this->assertSame('rejected', $push('pl_assignments', ['user_id' => $awa->id, 'title' => 'X', 'start_date' => $monday->toDateString(), 'end_date' => $monday->toDateString(), 'hours_per_day' => 2], null, fn () => $this->api($awaToken))['status']);

        // Congé approuvé d'Awa le mercredi, affectée toute la semaine
        $eAwa = $this->uuid();
        $this->ok($push('rh_employees', ['first_name' => 'Awa', 'last_name' => 'Graphiste', 'base_salary' => 200000, 'hire_date' => '2025-01-01', 'user_id' => $awa->id], $eAwa));
        $this->ok($push('rh_leaves', ['employee_id' => $eAwa, 'type' => 'annual', 'start_date' => $monday->copy()->addDays(2)->toDateString(), 'end_date' => $monday->copy()->addDays(2)->toDateString(), 'status' => 'approved']));
        $this->ok($push('pl_assignments', ['user_id' => $awa->id, 'partner_id' => $acme, 'title' => 'Charte graphique', 'start_date' => $monday->toDateString(), 'end_date' => $fri->toDateString(), 'hours_per_day' => 8]));
        // Temps réalisé
        $this->ok($push('prj_timesheets', ['project_id' => $prj, 'user_id' => $kofi->id, 'date' => $monday->toDateString(), 'hours' => 7, 'billable' => true]));

        $l = $api()->getJson('/api/planning/load?from='.$monday->toDateString().'&to='.$monday->copy()->addDays(6)->toDateString())->assertOk()->json('data');
        $u = collect($l['users'])->keyBy('name');
        $k = $u['Kofi Dev'];
        $this->assertEquals([40, 38, 7, 95, 2], [$k['capacity'], $k['planned'], $k['actual'], $k['occupation'], $k['overload_days']]);
        $this->assertEquals(10, $k['days'][$fri->toDateString()]['planned']); // 6 + 4 > 8 jeudi et vendredi
        $this->assertTrue($k['days'][$fri->toDateString()]['overload']);
        $a = $u['Awa Graphiste'];
        $this->assertEquals([32, 32, 100, 1], [$a['capacity'], $a['planned'], $a['occupation'], $a['leave_conflicts']]);
        $this->assertSame('Congé', $a['days'][$monday->copy()->addDays(2)->toDateString()]['off']);
        $this->assertEquals(0, $u['Admin Studio Digital']['planned']);
        $this->assertEquals(0, $u['Admin Studio Digital']['occupation']);

        // Disponibles au moins 4 h par jour cette semaine
        $av = collect($api()->getJson('/api/planning/available?from='.$monday->toDateString().'&to='.$fri->toDateString().'&hours=4')->json('data'))->pluck('name')->all();
        $this->assertContains('Admin Studio Digital', $av);
        $this->assertNotContains('Kofi Dev', $av);

        // Semaine type réglable, jour férié à zéro
        $api()->putJson('/api/planning/settings', ['week_hours' => 35, 'work_days' => [1, 2, 3, 4, 5]])->assertOk();
        $l = $api()->getJson('/api/planning/load?from=2027-11-15&to=2027-11-19')->json('data');
        $adm = collect($l['users'])->firstWhere('name', 'Admin Studio Digital');
        $this->assertEquals(28, $adm['capacity']); // lundi 15 novembre férié
        $this->assertSame('Journée nationale de la paix', $adm['days']['2027-11-15']['off']);
        $api()->getJson('/api/planning/load?from=2027-01-01&to=2027-06-30')->assertStatus(422);
        $this->api($awaToken)->getJson('/api/planning/load')->assertOk();
        $this->api($awaToken)->putJson('/api/planning/settings', ['week_hours' => 10])->assertForbidden();
        $this->assertSame(3, Assignment::count());
    }
}
