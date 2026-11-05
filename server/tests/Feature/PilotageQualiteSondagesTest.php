<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Qualite\QualiteHooks;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** v0.83 : pilotage (soldes de gestion, prévision, seuil de rentabilité, rentabilité), qualité, questionnaires. */
class PilotageQualiteSondagesTest extends TestCase
{
    public function test_pilotage_sig_forecast_breakeven_and_profitability(): void
    {
        $this->travelTo(now()->setDate((int) now()->year, 6, 15)->setTime(12, 0));
        [$company, $admin, $token] = $this->makeCompany('Pilotage SARL');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/pilotage/overview')->assertOk();
        $cli = $api()->postJson('/api/core/partners', ['name' => 'Client Or', 'type' => 'customer'])->json('data.id');
        $cli2 = $api()->postJson('/api/core/partners', ['name' => 'Client Argent', 'type' => 'customer'])->json('data.id');
        $p = $api()->postJson('/api/core/products', ['name' => 'Ordinateur', 'sale_price' => 500000, 'purchase_price' => 350000, 'tax_rate' => 18])->json('data.id');
        $sell = function ($partner, $date, $qty, $price, $product = null) use ($api) {
            $res = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(),
                'data' => ['type' => 'invoice', 'partner_id' => $partner, 'date' => $date, 'status' => 'validated', 'lines' => [['id' => $this->uuid(), 'product_id' => $product, 'description' => 'Vente', 'quantity' => $qty, 'unit_price' => $price, 'tax_rate' => 18, 'discount_percent' => 0]]]]]])->json('results.0');
            $this->assertSame('applied', $res['status'], json_encode($res));
        };
        $y = (int) now()->year;
        // 17 mois d'historique croissant, avec un pic en décembre
        for ($k = 17; $k >= 1; $k--) {
            $d = now()->startOfMonth()->subMonths($k);
            $sell($cli, $d->copy()->addDays(9)->toDateString(), 2 + ($d->month === 12 ? 4 : 0), 500000, $p);
            $sell($cli2, $d->copy()->addDays(12)->toDateString(), 1, 300000 + (17 - $k) * 10000);
        }
        $sell($cli, now()->startOfMonth()->addDays(2)->toDateString(), 2, 500000, $p); // mois en cours
        // charges : loyer et salaires en trésorerie
        $acc = (string) \Illuminate\Support\Str::uuid();
        foreach (range(1, 5) as $m) {
            foreach ([['rent', -300000], ['salaries', -400000], ['bank_fees', -10000]] as [$cat, $amount]) {
                DB::table('tr_operations')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'company_id' => $company->id, 'account_id' => $acc, 'date' => sprintf('%d-%02d-05', $y, $m),
                    'amount' => $amount, 'label' => $cat, 'category' => $cat, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $o = $api()->getJson('/api/pilotage/overview?from='.$y.'-01-01&to='.$y.'-05-31')->assertOk()->json('data');
        $l = collect($o['lines'])->keyBy('key');
        $rev = 5 * 1000000 + array_sum(array_map(fn ($k) => 300000 + (17 - $k) * 10000, [5, 4, 3, 2, 1]));
        $this->assertEquals($rev, $l['revenue']['value']);
        $this->assertEquals(5 * 700000, $l['cogs']['value']);
        $this->assertEquals(5 * 300000, $l['external']['value']);
        $this->assertEquals(5 * 400000, $l['personnel']['value']);
        $this->assertEquals($rev - 3500000 - 1500000 - 2000000, $l['ebitda']['value']);
        $this->assertEquals($rev - 3500000 - 1500000 - 2000000 - 50000, $l['result']['value']);
        $this->assertNotNull($l['revenue']['change']); // comparé à N-1
        $this->assertCount(5, $o['months']);

        $f = $api()->getJson('/api/pilotage/forecast')->assertOk()->json('data');
        $this->assertCount(12, $f['forecast']);
        $this->assertSame(17, $f['months_of_history']);
        $this->assertGreaterThan($f['season'][6], $f['season'][12]); // décembre plus fort
        $this->assertGreaterThan(0, $f['trend_monthly']);
        $this->assertGreaterThan($f['ytd'], $f['landing']);
        $this->assertNull($f['annual_target']);
        // objectif annuel réparti selon la saisonnalité
        $api()->postJson('/api/pilotage/records/targets', ['year' => $y, 'revenue' => 20000000])->assertCreated()->assertJsonPath('data.title', 'Année '.$y);
        $api()->postJson('/api/pilotage/records/targets', ['year' => $y, 'revenue' => 1])->assertStatus(422);
        $api()->postJson('/api/pilotage/records/targets', ['year' => $y, 'month' => '1', 'revenue' => 1500000])->assertCreated()->assertJsonPath('data.title', 'Janvier '.$y);
        $f = $api()->getJson('/api/pilotage/forecast')->assertOk()->json('data');
        $this->assertEqualsWithDelta(20000000, $f['annual_target'], 1);
        $this->assertEquals(1500000, $f['targets'][0]);
        $this->assertGreaterThan($f['targets'][5], $f['targets'][11]);

        $b = $api()->getJson('/api/pilotage/breakeven')->assertOk()->json('data');
        $this->assertSame(3, $b['window']);
        $this->assertEqualsWithDelta(710000, $b['average']['fixed'], 1);
        $this->assertGreaterThan(0, $b['breakeven']);
        $this->assertEquals(1000000, $b['month_to_date']);

        $pr = $api()->getJson('/api/pilotage/profitability?by=customer&from='.$y.'-01-01&to='.$y.'-06-30')->assertOk()->json('data');
        $this->assertSame('Client Or', $pr['rows'][0]['name']);
        $this->assertEquals(30, $pr['rows'][0]['margin_rate']);
        $this->assertSame(0, (int) $pr['rows'][1]['cost_known']); // services sans prix d'achat
        $this->assertEquals(6 * 1000000 * 0.3, collect($api()->getJson('/api/pilotage/profitability?by=product&from='.$y.'-01-01&to='.$y.'-06-30')->json('data.rows'))->firstWhere('name', 'Ordinateur')['margin']);
        // le commercial n'y a pas accès
        $u = User::create(['company_id' => $company->id, 'name' => 'Koffi', 'email' => 'koffi@pl.ci', 'password' => 'secret123', 'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $this->api($u->createToken('t')->plainTextToken)->getJson('/api/pilotage/overview')->assertForbidden();
    }

    public function test_quality_inspection_nonconformity_and_actions(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Qualité Agro SA');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/qualite/schema')->assertOk();
        $role = fn ($n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $ctrl = User::create(['company_id' => $company->id, 'name' => 'Fatou Contrôle', 'email' => 'ctrl@qa.ci', 'password' => 'secret123', 'role_id' => $role('Contrôleur qualité')]);
        $vend = User::create(['company_id' => $company->id, 'name' => 'Aya Vente', 'email' => 'aya@qa.ci', 'password' => 'secret123', 'role_id' => $role('Commercial')]);
        $capi = fn () => $this->api($ctrl->createToken('t')->plainTextToken);
        $vapi = fn () => $this->api($vend->createToken('t')->plainTextToken);

        $plan = $api()->postJson('/api/qualite/records/plans', ['title' => 'Réception des matières premières', 'scope' => 'reception', 'threshold' => 80, 'owner_id' => $admin->id,
            'criteria' => "Température < 4 °C\n- Emballage intact\nDLC supérieure à 10 jours\nÉtiquetage conforme\nBon de livraison complet"])->assertCreated()->json('data');
        $api()->postJson('/api/qualite/records/plans', ['title' => 'Vide', 'criteria' => "  \n "])->assertStatus(422);
        $this->assertSame(['Température < 4 °C', 'Emballage intact', 'DLC supérieure à 10 jours', 'Étiquetage conforme', 'Bon de livraison complet'], $capi()->getJson('/api/qualite/plans/'.$plan['id'].'/criteria')->json('data.criteria'));

        // contrôle conforme avec réserve (1 non-conformité sur 5 = 80 %), puis non conforme → non-conformité ouverte
        $i1 = $capi()->postJson('/api/qualite/records/inspections', ['plan_id' => $plan['id'], 'subject' => 'Lot poulet L-118'])->assertCreated()->assertJsonPath('data.result', 'pending')->json('data');
        $ans = fn (array $v) => ['answers' => array_map(fn ($x) => ['value' => $x, 'comment' => $x === 'nok' ? 'Constaté à réception' : null], $v)];
        $capi()->postJson('/api/qualite/inspections/'.$i1['id'].'/answers', ['answers' => [['value' => 'ok']]])->assertStatus(422);
        $capi()->postJson('/api/qualite/inspections/'.$i1['id'].'/answers', $ans(['ok', 'ok', 'nok', 'ok', 'ok']))->assertOk()->assertJsonPath('data.result', 'reserve')->assertJsonPath('data.score', 80);
        $i2 = $capi()->postJson('/api/qualite/records/inspections', ['plan_id' => $plan['id'], 'subject' => 'Lot poulet L-119'])->assertCreated()->json('data');
        $r2 = $capi()->postJson('/api/qualite/inspections/'.$i2['id'].'/answers', $ans(['nok', 'nok', 'ok', 'ok', 'na']))->assertOk()->assertJsonPath('data.result', 'nok')->assertJsonPath('data.score', 50)->json('data');
        $nc = DB::table('qa_nonconformities')->find($r2['nc_id']);
        $this->assertSame('NC-'.now()->format('Y').'-0001', $nc->number);
        $this->assertStringContainsString('Température < 4 °C : Constaté à réception', $nc->description);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'Contrôle non conforme')->exists());

        // réclamation client déclarée par une vendeuse (majeure → responsable prévenu)
        $rec = $vapi()->postJson('/api/qualite/records/nonconformities', ['title' => 'Client livré avec du poulet décongelé', 'source' => 'customer', 'severity' => 'major', 'status' => 'closed'])
            ->assertCreated()->assertJsonPath('data.status', 'open')->json('data');
        $this->assertSame(now()->addDays(7)->toDateString(), $rec['due_date']);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Non-conformité majeure%')->exists());
        $vapi()->getJson('/api/qualite/records/nonconformities')->assertOk()->assertJsonCount(1, 'data');
        $vapi()->getJson('/api/qualite/records/actions')->assertForbidden();

        // actions : la NC avance toute seule ; clôture seulement après actions faites et efficacité vérifiée
        $a1 = $api()->postJson('/api/qualite/records/actions', ['nc_id' => $rec['id'], 'title' => 'Contrôler la température dans le camion', 'owner_id' => $ctrl->id, 'due_date' => now()->addDays(3)->toDateString()])->assertCreated()->json('data');
        $this->assertSame('action', DB::table('qa_nonconformities')->find($rec['id'])->status);
        $this->assertTrue(AppNotification::where('user_id', $ctrl->id)->where('title', 'Action qualité confiée')->exists());
        $api()->putJson('/api/qualite/records/nonconformities/'.$rec['id'], ['status' => 'closed'])->assertStatus(422);
        $capi()->putJson('/api/qualite/records/actions/'.$a1['id'], ['status' => 'verified'])->assertStatus(422);
        $capi()->putJson('/api/qualite/records/actions/'.$a1['id'], ['status' => 'done', 'result' => 'Thermomètre installé'])->assertOk()->assertJsonPath('data.done_on', now()->toDateString());
        $this->assertSame('verification', DB::table('qa_nonconformities')->find($rec['id'])->status);
        $api()->putJson('/api/qualite/records/nonconformities/'.$rec['id'], ['status' => 'closed'])->assertStatus(422); // efficacité pas vérifiée
        $api()->putJson('/api/qualite/records/nonconformities/'.$rec['id'], ['status' => 'closed', 'effective' => true, 'cost' => 85000])->assertOk()->assertJsonPath('data.closed_on', now()->toDateString());

        // action en retard : rappel hebdomadaire
        $api()->postJson('/api/qualite/records/actions', ['nc_id' => $nc->id, 'title' => 'Former les réceptionnaires', 'owner_id' => $ctrl->id, 'due_date' => now()->subDay()->toDateString()])->assertCreated()->assertJsonPath('data.alert', 'En retard de 1 j');
        $this->assertGreaterThanOrEqual(1, app(QualiteHooks::class)->alerts($company));
        $this->assertTrue(AppNotification::where('user_id', $ctrl->id)->where('title', 'Action qualité à réaliser')->exists());

        $d = $api()->getJson('/api/qualite/dashboard')->assertOk()->json('data');
        $this->assertSame(1, $d['open']);
        $this->assertEquals(50, $d['conformity']);
        $this->assertEquals(85000, $d['cost_year']);
        $this->assertSame(1, $d['complaints']);
        $api()->deleteJson('/api/qualite/records/plans/'.$plan['id'])->assertStatus(422);
    }

    public function test_questionnaire_public_link_results_and_unhappy_alert(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Resto Sondage');
        $api = fn () => $this->api($token);
        $f = $api()->postJson('/api/questionnaires/records/forms', ['title' => 'Votre avis sur notre restaurant', 'kind' => 'satisfaction'])->assertCreated()->json('data');
        $this->assertSame(6, $f['questions_count']);
        $qs = $api()->getJson('/api/questionnaires/forms/'.$f['id'].'/questions')->assertOk()->json('data.questions');
        // personnalisation : un choix unique en plus
        $qs[] = ['type' => 'single', 'label' => 'Comment nous avez-vous connus ?', 'options' => ['Bouche à oreille', 'Facebook', 'Passage', 'Facebook']];
        $api()->putJson('/api/questionnaires/forms/'.$f['id'].'/questions', ['questions' => [['type' => 'single', 'label' => 'X', 'options' => ['A']]]])->assertStatus(422);
        $qs = $api()->putJson('/api/questionnaires/forms/'.$f['id'].'/questions', ['questions' => $qs])->assertOk()->json('data.questions');
        $this->assertSame(['Bouche à oreille', 'Facebook', 'Passage'], $qs[6]['options']);
        $id = fn ($i) => $qs[$i]['id'];

        // brouillon : pas de page publique
        $tok = DB::table('qs_forms')->find($f['id'])->token;
        $this->get('/q/'.$tok)->assertNotFound();
        $api()->putJson('/api/questionnaires/records/forms/'.$f['id'], ['status' => 'open'])->assertOk();
        $this->get('/q/'.$tok)->assertOk()->assertSee('Votre avis sur notre restaurant')->assertSee('Comment nous avez-vous connus ?');

        // réponse publique : obligatoire manquante, puis valide ; un robot est ignoré
        $this->post('/q/'.$tok, ['a' => [$id(1) => 4]])->assertOk()->assertSee('Réponse obligatoire.');
        $this->post('/q/'.$tok, ['a' => [$id(0) => 5, $id(1) => 5, $id(4) => 10, $id(6) => 'Facebook', $id(5) => 'Rien à redire'], 'name' => 'Awa', 'phone' => '0707070707'])->assertOk()->assertSee('Merci');
        $this->post('/q/'.$tok, ['a' => [$id(0) => 1], 'website' => 'spam'])->assertOk();
        $this->assertSame(1, DB::table('qs_responses')->count());
        // client mécontent : le responsable est prévenu
        $this->post('/q/'.$tok, ['a' => [$id(0) => 2, $id(4) => 3, $id(6) => 'Passage', $id(5) => 'Attente trop longue'], 'name' => 'Koné', 'phone' => '0102030405'])->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Répondant mécontent%')->where('body', 'like', '%Koné (0102030405)%')->exists());
        // saisie dans l'application
        $api()->postJson('/api/questionnaires/forms/'.$f['id'].'/responses', ['answers' => [$id(0) => 4, $id(4) => 8, $id(6) => 'Bouche à oreille']])->assertCreated()->assertJsonPath('data.mood', 'happy');
        $api()->postJson('/api/questionnaires/forms/'.$f['id'].'/responses', ['answers' => [$id(0) => 9]])->assertStatus(422)->assertJsonStructure(['errors']);
        $api()->postJson('/api/questionnaires/records/responses', ['form_id' => $f['id']])->assertStatus(422);

        $r = $api()->getJson('/api/questionnaires/forms/'.$f['id'].'/results')->assertOk()->json('data');
        $this->assertSame(3, $r['responses']);
        $q = collect($r['questions'])->keyBy('id');
        $this->assertEquals(3.67, $q[$id(0)]['average']);
        $this->assertSame(67, $q[$id(0)]['satisfied']);
        $this->assertSame(0, $q[$id(4)]['nps']); // 1 promoteur, 1 passif, 1 détracteur
        $this->assertSame(1, collect($q[$id(6)]['options'])->firstWhere('label', 'Facebook')['count']);
        $this->assertEqualsCanonicalizing(['Rien à redire', 'Attente trop longue'], $q[$id(5)]['answers']);
        $this->assertSame('67 % satisfaits · 1 mécontent(s)', $api()->getJson('/api/questionnaires/records/forms/'.$f['id'])->json('data.summary'));
        $this->assertTrue(collect($api()->getJson('/api/questionnaires/records/responses?form_id='.$f['id'])->json('data'))->contains(fn ($x) => str_contains((string) $x['digest'], 'Accueil et amabilité de l\'équipe : 5/5')));
        $api()->get('/api/questionnaires/forms/'.$f['id'].'/export')->assertOk()->assertDownload();

        // fermeture : nombre maximum de réponses
        $api()->putJson('/api/questionnaires/records/forms/'.$f['id'], ['max_responses' => 4])->assertOk();
        $this->post('/q/'.$tok, ['a' => [$id(0) => 5]])->assertOk();
        $this->get('/q/'.$tok)->assertOk()->assertSee('Ce questionnaire est fermé');
        $this->assertSame('closed', DB::table('qs_forms')->find($f['id'])->status);
        $dup = $api()->postJson('/api/questionnaires/forms/'.$f['id'].'/duplicate')->assertCreated()->json('data');
        $this->assertSame('draft', $dup['status']);
        $this->assertSame(7, $dup['questions_count']);
    }
}
