<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Analytique\Models\AnLine;
use App\Modules\Analytique\Models\Section;
use Tests\TestCase;

class AnalytiqueTest extends TestCase
{
    public function test_axes_sections_rules_distribution_reports_required_axis_budgets_commitments_alerts_expenses_payroll_and_projects(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Groupe Koffi');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = function (array $muts) use ($api) {
            return $api()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid(), 'op' => 'upsert'], $muts)])->assertOk()->json('results');
        };
        $mk = function (string $entity, array $data) use ($push) {
            $id = $this->uuid();
            $r = $push([['entity' => $entity, 'record_id' => $id, 'data' => $data]])[0];
            $this->assertSame('applied', $r['status'], json_encode($r));

            return $id;
        };

        // Axes et sections
        $activite = $mk('an_axes', ['name' => 'Activité', 'code' => 'ACT', 'sort' => 1]);
        $site = $mk('an_axes', ['name' => 'Site', 'code' => 'SITE', 'sort' => 2]);
        $negoce = $mk('an_sections', ['axis_id' => $activite, 'code' => 'NEG', 'name' => 'Négoce']);
        $riz = $mk('an_sections', ['axis_id' => $activite, 'code' => 'NEG-RIZ', 'name' => 'Négoce — Riz', 'parent_id' => $negoce]);
        $services = $mk('an_sections', ['axis_id' => $activite, 'code' => 'SRV', 'name' => 'Services']);
        $abidjan = $mk('an_sections', ['axis_id' => $site, 'code' => 'ABJ', 'name' => 'Abidjan', 'manager_id' => $admin->id]);
        $bouake = $mk('an_sections', ['axis_id' => $site, 'code' => 'BKE', 'name' => 'Bouaké']);
        // Section parente d'un autre axe refusée
        $this->assertSame('rejected', $push([['entity' => 'an_sections', 'record_id' => $this->uuid(), 'data' => ['axis_id' => $site, 'name' => 'X', 'parent_id' => $negoce]]])[0]['status']);

        $p = fn (array $d) => $api()->postJson('/api/core/partners', $d)->assertCreated()->json('data.id');
        $client = $p(['name' => 'Supermarché Bamba', 'type' => 'customer']);
        $fournisseur = $p(['name' => 'Moulins du Nord', 'type' => 'supplier']);
        $prodRiz = $api()->postJson('/api/core/products', ['name' => 'Riz 25 kg', 'sale_price' => 15000, 'tax_rate' => 18])->assertCreated()->json('data.id');

        // Règles : le riz va en « Négoce — Riz », les achats chez Moulins du Nord à Abidjan, le service Commercial à Abidjan
        $mk('an_rules', ['applies_to' => 'sale', 'product_id' => $prodRiz, 'distribution' => [$riz => 100]]);
        $mk('an_rules', ['applies_to' => 'purchase', 'partner_id' => $fournisseur, 'distribution' => [$abidjan => 100]]);
        $mk('an_rules', ['applies_to' => 'payroll', 'department' => 'Commercial', 'distribution' => [$abidjan => 70, $bouake => 30]]);
        // Règle sans critère, ou dépassant 100 % sur un axe : refusées
        $this->assertSame('rejected', $push([['entity' => 'an_rules', 'record_id' => $this->uuid(), 'data' => ['distribution' => [$negoce => 100]]]])[0]['status']);
        $r = $push([['entity' => 'an_rules', 'record_id' => $this->uuid(), 'data' => ['partner_id' => $client, 'distribution' => [$negoce => 60, $services => 50]]]])[0];
        $this->assertSame('La ventilation dépasse 100 % sur un même axe.', $r['message']);

        $doc = function (string $entity, array $data) use ($mk) {
            return $mk($entity, $data + ['date' => now()->toDateString(), 'status' => 'validated']);
        };
        $line = fn (string $desc, float $qty, float $price, array $extra = []) => ['id' => $this->uuid(), 'description' => $desc, 'quantity' => $qty, 'unit_price' => $price, 'tax_rate' => 18] + $extra;

        // Facture : ligne riz (règle) + prestation ventilée 60/40 sur la ligne ; site 50/50 sur le document
        $inv = $doc('sales_documents', ['type' => 'invoice', 'partner_id' => $client, 'analytic' => [$abidjan => 50, $bouake => 50], 'lines' => [
            $line('Riz 25 kg', 10, 10000, ['product_id' => $prodRiz]),
            $line('Livraison et installation', 1, 50000, ['analytic' => [$services => 60, $negoce => 40]]),
        ]]);
        $rows = AnLine::withoutGlobalScopes()->where('source_id', $inv)->get();
        $this->assertEquals(100000, $rows->where('section_id', $riz)->sum('amount'));
        $this->assertEquals(30000, $rows->where('section_id', $services)->sum('amount'));
        $this->assertEquals(20000, $rows->where('section_id', $negoce)->sum('amount'));
        $this->assertEquals(75000, $rows->where('section_id', $abidjan)->sum('amount')); // l'axe Site du document s'ajoute à la ligne
        $this->assertEquals(75000, $rows->where('section_id', $bouake)->sum('amount'));
        $this->assertSame('701', $rows->where('section_id', $riz)->first()->account_code);

        // Ventilation corrigée après validation : recalcul
        $api()->putJson("/api/ventes/documents/$inv", ['analytic' => [$abidjan => 100]])->assertOk();
        $this->assertEquals(150000, AnLine::withoutGlobalScopes()->where('source_id', $inv)->where('section_id', $abidjan)->sum('amount'));
        $this->assertEquals(0, AnLine::withoutGlobalScopes()->where('source_id', $inv)->where('section_id', $bouake)->count());

        // Avoir partiel (riz) et facture non ventilée
        $doc('sales_documents', ['type' => 'credit_note', 'partner_id' => $client, 'source_id' => $inv, 'lines' => [$line('Riz 25 kg', 2, 10000, ['product_id' => $prodRiz])]]);
        $doc('sales_documents', ['type' => 'invoice', 'partner_id' => $client, 'lines' => [$line('Conseil', 1, 40000)]]);

        // Facture fournisseur : site par la règle du fournisseur, activité saisie
        $ff = $doc('purchase_documents', ['type' => 'supplier_invoice', 'partner_id' => $fournisseur, 'analytic' => [$negoce => 100], 'lines' => [$line('Riz en gros', 100, 6000)]]);
        $this->assertEquals(-600000, AnLine::withoutGlobalScopes()->where('source_id', $ff)->where('section_id', $abidjan)->sum('amount'));
        $this->assertSame('601', AnLine::withoutGlobalScopes()->where('source_id', $ff)->first()->account_code);

        // Rapport par activité : sous-sections cumulées, marge, part non ventilée
        $rep = $api()->getJson("/api/analytique/report?axis_id=$activite")->assertOk()->json('data');
        $byCode = collect($rep['sections'])->keyBy('code');
        $this->assertEquals(80000, $byCode['NEG-RIZ']['revenue']);
        $this->assertEquals(20000, $byCode['NEG']['revenue']);
        $this->assertEquals(600000, $byCode['NEG']['cost']);
        $this->assertEquals(100000, $byCode['NEG']['total_revenue']);
        $this->assertEquals(-500000, $byCode['NEG']['total_margin']);
        $this->assertEquals(30000, $byCode['SRV']['revenue']);
        $this->assertEquals(170000, $rep['totals']['base_revenue']); // 150 000 − 20 000 + 40 000
        $this->assertEquals(40000, $rep['totals']['unallocated_revenue']);
        $this->assertEquals(0, $rep['totals']['unallocated_cost']);
        $this->assertCount(2, $rep['axes']);
        $detail = $api()->getJson("/api/analytique/sections/$negoce")->assertOk()->json('data');
        $this->assertEquals(100000, $detail['revenue']); // Négoce + Riz
        $this->assertSame(['601', '701'], collect($detail['accounts'])->pluck('code')->all());
        $this->assertNotNull(collect($detail['lines'])->firstWhere('source_type', 'document')['document']['number']);

        // Axe obligatoire pour les achats : bon de commande sans site refusé
        $push([['entity' => 'an_axes', 'record_id' => $site, 'data' => ['required' => true]]]);
        $poId = $this->uuid();
        $r = $push([['entity' => 'purchase_documents', 'record_id' => $poId, 'data' => ['type' => 'purchase_order', 'partner_id' => $p(['name' => 'Imprimerie', 'type' => 'supplier']), 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [$line('Flyers', 1, 100000)]]]])[0];
        $this->assertSame('rejected', $r['status']);
        $this->assertStringContainsString('axe « Site »', $r['message']);

        // Budget annuel : Abidjan 1 000 000 de charges, réparti sur 12 mois, responsable prévenu
        $year = now()->year;
        $gestionnaire = User::create(['company_id' => $company->id, 'name' => 'Awa', 'email' => 'awa@koffi.ci', 'password' => 'secret123', 'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $b = $api()->postJson('/api/analytique/budgets', ['name' => "Budget $year", 'start_date' => "$year-01-01", 'end_date' => "$year-12-31", 'warn_percent' => 80])->assertCreated()->json('data');
        $api()->postJson("/api/analytique/budgets/{$b['id']}/approve")->assertStatus(422); // sans ligne
        $b = $api()->putJson("/api/analytique/budgets/{$b['id']}", ['lines' => [
            ['kind' => 'expense', 'section_id' => $abidjan, 'total' => 1000000, 'owner_id' => $gestionnaire->id, 'label' => 'Charges Abidjan'],
            ['kind' => 'revenue', 'section_id' => $services, 'amounts' => [now()->format('Y-m') => 100000]],
            ['kind' => 'expense', 'account_prefix' => '601', 'total' => 500000],
        ]])->assertOk()->json('data');
        $this->assertCount(12, $b['lines'][0]['amounts']);
        $this->assertEquals(1000000, array_sum($b['lines'][0]['amounts']));
        $this->assertEquals(83333, $b['lines'][0]['amounts']["$year-01"]); // francs CFA entiers, le reste sur décembre
        $this->assertEquals(83337, $b['lines'][0]['amounts']["$year-12"]);
        $this->assertEquals(600000, $b['lines'][0]['realized']);
        $this->assertEquals(60, $b['lines'][0]['percent']);
        $this->assertEquals(30000, $b['lines'][1]['realized']);
        $this->assertEquals(600000, $b['lines'][2]['realized']); // budget par compte : grand livre (compte 601)
        $api()->postJson("/api/analytique/budgets/{$b['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        // Bon de commande Abidjan 150 000 HT : engagé → 75 % (pas d'alerte), puis 100 000 de plus → 85 % : alerte
        $po = $doc('purchase_documents', ['type' => 'purchase_order', 'partner_id' => $fournisseur, 'analytic' => [$abidjan => 100], 'lines' => [$line('Sacs', 1, 150000)]]);
        $this->assertFalse(AppNotification::where('user_id', $gestionnaire->id)->where('type', 'budget')->exists());
        $doc('purchase_documents', ['type' => 'purchase_order', 'partner_id' => $fournisseur, 'analytic' => [$abidjan => 100], 'lines' => [$line('Palettes', 1, 100000)]]);
        $n = AppNotification::where('user_id', $gestionnaire->id)->where('type', 'budget')->get();
        $this->assertCount(1, $n);
        $this->assertStringContainsString('Budget consommé à 85 %', $n[0]->title);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('type', 'budget')->exists()); // section dont il est responsable
        $s = $api()->getJson("/api/analytique/budgets/{$b['id']}")->json('data');
        $this->assertEquals(250000, $s['lines'][0]['committed']);
        $this->assertEquals(150000, $s['lines'][0]['remaining']);
        // Statut budgétaire pendant la saisie d'un achat
        $st = $api()->getJson('/api/analytique/budget-status?sections[]='.$abidjan)->assertOk()->json('data.0');
        $this->assertEquals(150000, $st['remaining']);

        // Facture fournisseur issue du premier bon de commande : l'engagement devient du réalisé, puis dépassement
        $doc('purchase_documents', ['type' => 'supplier_invoice', 'partner_id' => $fournisseur, 'source_id' => $po, 'analytic' => [$abidjan => 100], 'lines' => [$line('Sacs', 1, 150000)]]);
        $s = $api()->getJson("/api/analytique/budgets/{$b['id']}")->json('data');
        $this->assertEquals(750000, $s['lines'][0]['realized']);
        $this->assertEquals(100000, $s['lines'][0]['committed']);
        $this->assertEquals(1, AppNotification::where('user_id', $gestionnaire->id)->where('type', 'budget')->count()); // même seuil : pas de doublon
        $doc('purchase_documents', ['type' => 'supplier_invoice', 'partner_id' => $fournisseur, 'analytic' => [$abidjan => 100], 'lines' => [$line('Transport', 1, 200000)]]);
        $last = AppNotification::where('user_id', $gestionnaire->id)->where('type', 'budget')->latest('id')->first();
        $this->assertStringContainsString('Budget dépassé', $last->title);
        $this->assertSame(2, $api()->getJson('/api/analytique/budgets')->json('data.0.over')); // Abidjan et compte 601

        // Réaffectation manuelle (loyer réparti) puis suppression
        $api()->postJson('/api/analytique/manual', ['date' => now()->toDateString(), 'label' => 'Loyer siège réparti', 'amount' => -300000, 'account_code' => '622', 'distribution' => [$negoce => 50, $services => 50]])->assertCreated();
        $man = AnLine::withoutGlobalScopes()->where('source_type', 'manual')->first();
        $this->assertEquals(-150000, $man->amount);
        $rep = $api()->getJson("/api/analytique/report?axis_id=$activite")->json('data');
        $this->assertEquals(150000, collect($rep['sections'])->firstWhere('code', 'SRV')['cost']);
        $api()->deleteJson("/api/analytique/manual/{$man->id}")->assertOk();
        $this->assertSame(0, AnLine::withoutGlobalScopes()->where('source_type', 'manual')->count());

        // Notes de frais : axe obligatoire, ventilation choisie par le responsable
        $push([['entity' => 'an_axes', 'record_id' => $site, 'data' => ['required' => false]]]);
        $push([['entity' => 'an_axes', 'record_id' => $activite, 'data' => ['required' => true]]]);
        $emp = $api()->postJson('/api/rh/employees', ['matricule' => 'M-001', 'first_name' => 'Yao', 'last_name' => 'Kouassi', 'department' => 'Commercial', 'base_salary' => 300000, 'hire_date' => now()->subYear()->toDateString()])->assertCreated()->json('id');
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $exp = $this->uuid();
        $push([['entity' => 'nf_expenses', 'record_id' => $exp, 'data' => ['employee_id' => $emp, 'date' => now()->toDateString(), 'amount' => 25000, 'description' => 'Carburant tournée']]]);
        \DB::table('nf_expenses')->where('id', $exp)->update(['receipt_path' => 'x.jpg']);
        $r = $push([['entity' => 'nf_expenses', 'record_id' => $exp, 'data' => ['status' => 'submitted']]])[0];
        $this->assertSame('rejected', $r['status']);
        $this->assertStringContainsString('Activité', $r['message']);
        $this->assertSame('applied', $push([['entity' => 'nf_expenses', 'record_id' => $exp, 'data' => ['status' => 'submitted', 'analytic' => [$services => 100]]]])[0]['status']);
        $this->assertSame('applied', $push([['entity' => 'nf_expenses', 'record_id' => $exp, 'data' => ['status' => 'approved']]])[0]['status']);
        $this->assertEquals(-25000, AnLine::withoutGlobalScopes()->where('source_type', 'expense')->where('section_id', $services)->sum('amount'));

        // Paie : coût employeur du service Commercial réparti 70 / 30
        $run = $api()->postJson('/api/paie/runs', ['period' => now()->format('Y-m')])->assertCreated()->json('run');
        $api()->postJson('/api/paie/runs/'.$run['id'].'/validate')->assertOk();
        $cost = (float) \DB::table('paie_payslips')->where('run_id', $run['id'])->sum('cost');
        $this->assertGreaterThan(300000, $cost);
        $this->assertEqualsWithDelta(-$cost * 0.7, AnLine::withoutGlobalScopes()->where('source_type', 'payroll')->where('section_id', $abidjan)->sum('amount'), 0.05);
        $this->assertSame('66', AnLine::withoutGlobalScopes()->where('source_type', 'payroll')->value('account_code'));
        $api()->postJson('/api/paie/runs/'.$run['id'].'/reopen')->assertOk();
        $this->assertSame(0, AnLine::withoutGlobalScopes()->where('source_type', 'payroll')->count());

        // Projets : section créée automatiquement, factures du projet rattachées
        $prj = $api()->postJson('/api/projets/projects', ['name' => 'Entrepôt Yopougon', 'code' => 'ENT-01', 'partner_id' => $client, 'billing_mode' => 'fixed'])->assertCreated()->json('data');
        $prjSection = Section::withoutGlobalScopes()->where('project_id', $prj['id'])->first();
        $this->assertSame('Entrepôt Yopougon', $prjSection->name);
        $ms = $api()->postJson('/api/projets/milestones', ['project_id' => $prj['id'], 'name' => 'Fondations', 'amount' => 500000])->assertCreated()->json('data');
        $invId = $api()->postJson("/api/projets/projects/{$prj['id']}/invoice", ['milestone_id' => $ms['id']])->assertOk()->json('invoice.id');
        $api()->putJson("/api/ventes/documents/$invId", ['status' => 'validated'])->assertOk();
        $this->assertEquals(500000, AnLine::withoutGlobalScopes()->where('section_id', $prjSection->id)->sum('amount'));

        // Budget créé à partir du réalisé (+10 %)
        $b2 = $api()->postJson('/api/analytique/budgets', ['name' => 'Budget '.($year + 1), 'start_date' => ($year + 1).'-01-01', 'end_date' => ($year + 1).'-12-31', 'copy_from' => "$year-01-01", 'copy_percent' => 10, 'axis_id' => $activite])->assertCreated()->json('data');
        $srv = collect($b2['lines'])->where('section_id', $services)->firstWhere('kind', 'revenue');
        $this->assertEquals(33000, $srv['total']); // 30 000 + 10 %

        // Synchronisation, droits, suppressions protégées
        $pull = $api()->getJson('/api/sync/pull?cursor=0&limit=2000')->json('changes');
        $this->assertCount(3, $pull['an_axes']['upserts'] ?? []); // Activité, Site, Projets (automatique)
        $this->assertGreaterThanOrEqual(6, count($pull['an_sections']['upserts']));
        $caisse = User::create(['company_id' => $company->id, 'name' => 'Caissier', 'email' => 'c@koffi.ci', 'password' => 'secret123', 'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Caissier')->value('id')]);
        $this->api($caisse->createToken('t')->plainTextToken)->getJson('/api/analytique/report')->assertForbidden();
        $this->assertSame('rejected', $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'an_sections', 'op' => 'delete', 'record_id' => $negoce]]])->json('results.0.status'));
        $api()->deleteJson("/api/analytique/budgets/{$b['id']}")->assertStatus(422); // approuvé
        $api()->postJson("/api/analytique/budgets/{$b['id']}/close")->assertOk()->assertJsonPath('data.status', 'closed');
        $api()->putJson("/api/analytique/budgets/{$b['id']}", ['lines' => []])->assertStatus(422);

        // Tout recalculer (après de nouvelles règles)
        $res = $api()->postJson('/api/analytique/rebuild')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(7, $res['documents']);
        $this->assertEquals(500000, AnLine::withoutGlobalScopes()->where('section_id', $prjSection->id)->sum('amount'));
    }

    public function test_analytic_ignored_when_module_disabled(): void
    {
        config(['erp.mode' => 'saas']);
        [, , $token] = $this->makeCompany('Petite Boutique', 'starter');
        $id = $this->uuid();
        $r = $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $id,
            'data' => ['type' => 'invoice', 'date' => now()->toDateString(), 'status' => 'validated', 'analytic' => [$this->uuid() => 100], 'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1000, 'analytic' => [$this->uuid() => 100]]]]]]])
            ->assertOk()->json('results.0');
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertNull($r['record']['analytic']);
    }
}
