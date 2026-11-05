<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Analytique\Models\AnLine;
use App\Modules\Compta\Models\Entry;
use App\Modules\Immobilisations\Depreciation;
use App\Modules\Immobilisations\Models\AssetLine;
use Tests\TestCase;

class ImmobilisationsTest extends TestCase
{
    public function test_depreciation_engine_linear_degressive_monthly_and_disposal_prorata(): void
    {
        // Linéaire 3 ans, mise en service le 15 avril : 256/1080 la 1re année, solde la 4e
        $l = Depreciation::schedule(['value' => 3000000, 'method' => 'linear', 'years' => 3, 'in_service_date' => '2026-04-15']);
        $this->assertSame([711111.0, 1000000.0, 1000000.0, 288889.0], array_map(fn ($x) => (float) $x['amount'], $l));
        $this->assertSame('2029-04-14', end($l)['period_end']);
        $this->assertEquals(0, end($l)['nbv']);
        // Dégressif 5 ans (coefficient 2) : départ au 1er du mois d'acquisition, bascule en linéaire en 2029
        $d = Depreciation::schedule(['value' => 10000000, 'method' => 'degressive', 'years' => 5, 'in_service_date' => '2026-04-15', 'acquisition_date' => '2026-04-10']);
        $this->assertSame([3000000.0, 2800000.0, 1680000.0, 1120000.0, 1120000.0, 280000.0], array_map(fn ($x) => (float) $x['amount'], $d));
        $this->assertEquals(2.0, Depreciation::defaultCoefficient(5));
        $this->assertEquals(2.5, Depreciation::defaultCoefficient(8));
        // Mensuel avec sortie le 15 mars : demi-mois
        $m = Depreciation::schedule(['value' => 1200000, 'method' => 'linear', 'years' => 1, 'in_service_date' => '2026-01-01', 'frequency' => 'monthly', 'until' => '2026-03-15']);
        $this->assertSame([100000.0, 100000.0, 50000.0], array_map(fn ($x) => (float) $x['amount'], $m));
        // Valeur résiduelle et non amortissable
        $this->assertEquals(800000, array_sum(array_column(Depreciation::schedule(['value' => 1000000, 'salvage' => 200000, 'method' => 'linear', 'years' => 4, 'in_service_date' => '2026-01-01']), 'amount')));
        $this->assertSame([], Depreciation::schedule(['value' => 5000000, 'method' => 'none', 'years' => 1, 'in_service_date' => '2026-01-01']));
        $this->assertSame(30, Depreciation::days360(\Illuminate\Support\Carbon::parse('2026-02-01'), \Illuminate\Support\Carbon::parse('2026-02-28')));
    }

    public function test_assets_lifecycle_accounting_disposal_register_from_invoice_analytic_and_permissions(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Transports Diallo');
        $api = fn () => $this->api($token);
        $pull = $api()->getJson('/api/sync/pull?cursor=0&limit=2000')->assertOk()->json('changes');
        $cats = collect($pull['im_categories']['upserts'])->keyBy('name');
        $this->assertCount(8, $cats);
        $transport = $cats['Matériel de transport'];
        $info = $cats['Matériel informatique'];
        $this->assertSame('245', $transport['gl_asset']);
        $push = fn (array $m) => $api()->postJson('/api/sync/push', ['mutations' => array_map(fn ($x) => $x + ['id' => $this->uuid(), 'op' => 'upsert'], $m)])->assertOk()->json('results');
        $year = now()->year;

        // Brouillon saisi hors ligne : durée et méthode reprises de la catégorie
        $truck = $this->uuid();
        $r = $push([['entity' => 'im_assets', 'record_id' => $truck, 'data' => ['name' => 'Camion Mercedes Actros', 'category_id' => $transport['id'], 'value' => 48000000,
            'acquisition_date' => ($year - 1).'-07-01', 'in_service_date' => ($year - 1).'-07-01', 'serial_number' => 'WDB9634031L123456', 'post_acquisition' => true]]])[0];
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame(4, $r['record']['years']);
        $this->assertSame('draft', $r['record']['status']);
        $this->assertNull($r['record']['code']);

        // Simulation du plan
        $prev = $api()->postJson('/api/immobilisations/preview', ['value' => 48000000, 'method' => 'linear', 'years' => 4, 'in_service_date' => ($year - 1).'-07-01'])->assertOk()->json('data');
        $this->assertCount(5, $prev);
        $this->assertEquals(6000000, $prev[0]['amount']);

        // Mise en service : numéro, plan, écriture d'acquisition D 245 / C 481
        $r = $push([['entity' => 'im_assets', 'record_id' => $truck, 'data' => ['status' => 'running']]])[0];
        $this->assertSame('applied', $r['status'], json_encode($r));
        $this->assertSame('running', $r['record']['status']);
        $this->assertStringStartsWith('IMM-'.($year - 1), $r['record']['code']);
        $this->assertEquals(48000000, $r['record']['nbv']);
        $acq = Entry::withoutGlobalScopes()->where('source_type', 'asset')->where('kind', 'acquisition')->with('lines.account')->first();
        $this->assertEquals(48000000, $acq->total);
        $this->assertEqualsCanonicalizing(['245', '481'], $acq->lines->pluck('account.code')->all());
        $show = $api()->getJson("/api/immobilisations/assets/$truck")->assertOk()->json('data');
        $this->assertCount(5, $show['lines']);
        $this->assertEquals(6000000, $show['lines'][0]['amount']);
        $this->assertSame(($year - 1).'-12-31', $show['lines'][0]['period_end']);

        // En service : le plan est figé, seuls quelques champs se modifient
        $r = $push([['entity' => 'im_assets', 'record_id' => $truck, 'data' => ['value' => 50000000]]])[0];
        $this->assertSame('rejected', $r['status']);
        $this->assertSame('applied', $push([['entity' => 'im_assets', 'record_id' => $truck, 'data' => ['location' => 'Dépôt de San Pedro', 'name' => 'Camion Actros 1845']]])[0]['status']);
        $this->assertSame('rejected', $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'im_assets', 'op' => 'delete', 'record_id' => $truck]]])->json('results.0.status'));

        // Dotations échues : l'exercice précédent seulement (D 681 / C 2845)
        $res = $api()->postJson('/api/immobilisations/depreciate', ['until' => ($year - 1).'-12-31'])->assertOk()->json('data');
        $this->assertSame(1, $res['count']);
        $this->assertEquals(6000000, $res['total']);
        $dep = Entry::withoutGlobalScopes()->where('source_type', 'asset_dep')->with('lines.account')->first();
        $this->assertEqualsCanonicalizing(['681', '2845'], $dep->lines->pluck('account.code')->all());
        $this->assertSame(0, $api()->postJson('/api/immobilisations/depreciate', ['until' => ($year - 1).'-12-31'])->json('data.count')); // pas de doublon
        // Correction : annulation de la dernière dotation puis nouvelle comptabilisation
        $api()->postJson("/api/immobilisations/assets/$truck/unpost")->assertOk();
        $this->assertSame(0, Entry::withoutGlobalScopes()->where('source_type', 'asset_dep')->count());
        $api()->postJson('/api/immobilisations/depreciate', ['until' => ($year - 1).'-12-31'])->assertOk();

        // Ordinateur acheté sur facture fournisseur, ventilé en analytique, plan mensuel
        $axis = $this->uuid();
        $sec = $this->uuid();
        $push([['entity' => 'an_axes', 'record_id' => $axis, 'data' => ['name' => 'Agence']], ['entity' => 'an_sections', 'record_id' => $sec, 'data' => ['axis_id' => $axis, 'name' => 'Abidjan']]]);
        $supplier = $api()->postJson('/api/core/partners', ['name' => 'Ivoire Informatique', 'type' => 'supplier'])->assertCreated()->json('data.id');
        $ff = $this->uuid();
        $push([['entity' => 'purchase_documents', 'record_id' => $ff, 'data' => ['type' => 'supplier_invoice', 'partner_id' => $supplier, 'date' => "$year-01-10", 'status' => 'validated', 'analytic' => [$sec => 100],
            'lines' => [['id' => $this->uuid(), 'description' => 'Ordinateur portable HP EliteBook', 'quantity' => 1, 'unit_price' => 900000, 'tax_rate' => 18], ['id' => $this->uuid(), 'description' => 'Livraison', 'quantity' => 1, 'unit_price' => 10000, 'tax_rate' => 18]]]]]);
        $lineId = \DB::table('trade_document_lines')->where('document_id', $ff)->where('description', 'like', 'Ordinateur%')->value('id');
        $made = $api()->postJson('/api/immobilisations/from-document', ['document_id' => $ff, 'category_id' => $info['id'], 'line_ids' => [$lineId]])->assertCreated()->json('data');
        $this->assertCount(1, $made);
        $pc = $made[0];
        $this->assertEquals(900000, $pc['value']);
        $this->assertSame('601', $pc['acquisition_account']); // reclassement du compte d'achat
        $this->assertSame([$sec => 100], $pc['analytic']);
        $this->assertCount(0, $api()->postJson('/api/immobilisations/from-document', ['document_id' => $ff, 'category_id' => $info['id'], 'line_ids' => [$lineId]])->json('data')); // pas de doublon
        $push([['entity' => 'im_assets', 'record_id' => $pc['id'], 'data' => ['frequency' => 'monthly', 'status' => 'running']]]);
        $lines = AssetLine::withoutGlobalScopes()->where('asset_id', $pc['id'])->orderBy('seq')->get();
        $this->assertCount(37, $lines); // 10 janvier → 9 janvier dans 3 ans : 37 mois entamés
        $this->assertEquals(900000, $lines->sum('amount'));
        $reclass = Entry::withoutGlobalScopes()->where('source_id', $pc['id'])->where('kind', 'acquisition')->with('lines.account')->first();
        $this->assertEqualsCanonicalizing(['2442', '601'], $reclass->lines->pluck('account.code')->all());
        $api()->postJson('/api/immobilisations/depreciate', ['until' => "$year-03-31", 'asset_id' => $pc['id']])->assertOk()->assertJsonPath('data.count', 3);
        $this->assertEquals(-(float) $lines->take(3)->sum('amount'), AnLine::withoutGlobalScopes()->where('source_type', 'asset')->sum('amount'));
        $this->assertSame('681', AnLine::withoutGlobalScopes()->where('source_type', 'asset')->value('account_code'));

        // Cession du camion le 30 juin : dotation complémentaire au prorata, sortie de l'actif, prix de cession
        $api()->postJson("/api/immobilisations/assets/$truck/dispose", ['date' => ($year - 1).'-06-30', 'type' => 'sale', 'amount' => 30000000])->assertStatus(422); // avant la dernière dotation
        $d = $api()->postJson("/api/immobilisations/assets/$truck/dispose", ['date' => "$year-06-30", 'type' => 'sale', 'amount' => 38000000, 'note' => 'Vendu à Sotra'])->assertOk()->json('data');
        $this->assertSame('disposed', $d['status']);
        $this->assertEquals(12000000, $d['depreciated']); // 6 M (6 mois N-1) + 6 M (6 mois N)
        $this->assertEquals(36000000, $d['nbv']);
        $this->assertSame("$year-06-30", end($d['lines'])['period_end']);
        $disp = Entry::withoutGlobalScopes()->where('source_type', 'asset')->where('kind', 'disposal')->with('lines.account')->first();
        $byAcc = $disp->lines->groupBy('account.code')->map(fn ($g) => round($g->sum('debit') - $g->sum('credit')));
        $this->assertEquals(12000000, $byAcc['2845']);
        $this->assertEquals(36000000, $byAcc['81']);
        $this->assertEquals(-48000000, $byAcc['245']);
        $this->assertEquals(38000000, $byAcc['485']);
        $this->assertEquals(-38000000, $byAcc['82']);

        // Tableau des immobilisations de l'exercice
        $reg = $api()->getJson("/api/immobilisations/register?year=$year")->assertOk()->json('data');
        $t = collect($reg['assets'])->keyBy('code');
        $truckRow = $t[$d['code']];
        $this->assertEquals(48000000, $truckRow['gross_start']);
        $this->assertEquals(48000000, $truckRow['disposals']);
        $this->assertEquals(0, $truckRow['gross_end']);
        $this->assertEquals(6000000, $truckRow['dep_start']);
        $this->assertEquals(6000000, $truckRow['dep_year']);
        $this->assertEquals(12000000, $truckRow['dep_reversal']);
        $pcRow = collect($reg['assets'])->firstWhere('id', $pc['id']);
        $this->assertEquals(900000, $pcRow['additions']);
        $this->assertEquals(900000, $reg['totals']['gross_end']);
        $x = $api()->get("/api/immobilisations/register/export?year=$year")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $x->headers->get('content-type'));

        // Annulation de la sortie (erreur de saisie) : retour en service, écritures contre-passées
        $c = $api()->postJson("/api/immobilisations/assets/$truck/cancel-disposal")->assertOk()->json('data');
        $this->assertSame('running', $c['status']);
        $this->assertEquals(6000000, $c['depreciated']);
        $this->assertNotNull(Entry::withoutGlobalScopes()->where('source_type', 'asset')->where('kind', 'disposal')->first()->reversed_by_id);
        // Mise au rebut
        $api()->postJson("/api/immobilisations/assets/$truck/dispose", ['date' => "$year-03-31", 'type' => 'scrap'])->assertOk()->assertJsonPath('data.disposal_type', 'scrap');

        // Reprise d'un registre existant : amortissements antérieurs sans écriture
        $old = $this->uuid();
        $push([['entity' => 'im_assets', 'record_id' => $old, 'data' => ['name' => 'Groupe électrogène 60 kVA', 'category_id' => $cats['Matériel et outillage']['id'], 'value' => 12000000,
            'acquisition_date' => ($year - 3).'-01-01', 'opening_date' => ($year - 1).'-12-31', 'status' => 'running']]]);
        $o = $api()->getJson("/api/immobilisations/assets/$old")->json('data');
        $this->assertEquals(7200000, $o['depreciated']); // 3 années de 2,4 M reprises
        $this->assertSame('opening', $o['lines'][0]['kind']);
        $this->assertSame(0, Entry::withoutGlobalScopes()->where('source_id', $old)->count());

        // Contrôles de mise en service
        $bad = $this->uuid();
        $r = $push([['entity' => 'im_assets', 'record_id' => $bad, 'data' => ['name' => 'Sans valeur', 'category_id' => $info['id'], 'status' => 'running']]])[0];
        $this->assertSame('Indiquez la valeur d\'acquisition (HT).', $r['message']);
        $r = $push([['entity' => 'im_assets', 'record_id' => $bad, 'data' => ['name' => 'Imprimante', 'category_id' => $info['id'], 'value' => 300000, 'method' => 'degressive', 'years' => 2, 'status' => 'running']]])[0];
        $this->assertStringContainsString('au moins 3 ans', $r['message']);
        $this->assertSame('applied', $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'im_assets', 'op' => 'delete', 'record_id' => $bad]]])->json('results.0.status'));

        // Paramètres, comptabilisation automatique, droits
        $api()->putJson('/api/immobilisations/settings', ['auto_post' => true])->assertOk()->assertJsonPath('data.auto_post', true);
        $before = AssetLine::withoutGlobalScopes()->whereNotNull('posted_at')->count();
        $this->artisan('erp:assets-depreciate')->assertSuccessful();
        $this->assertGreaterThan($before, AssetLine::withoutGlobalScopes()->whereNotNull('posted_at')->count());
        $stats = $api()->getJson('/api/immobilisations/settings')->json('data.stats');
        $this->assertSame(2, $stats['count']);
        $this->assertSame(0, $stats['due_count']);
        $commercial = User::create(['company_id' => $company->id, 'name' => 'Ali', 'email' => 'ali@diallo.ci', 'password' => 'secret123', 'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $this->api($commercial->createToken('t')->plainTextToken)->getJson('/api/immobilisations/register')->assertForbidden();
        $this->assertSame('rejected', $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'im_categories', 'op' => 'delete', 'record_id' => $transport['id']]]])->json('results.0.status'));
    }
}
