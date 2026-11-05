<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Recrutement\Models\Application;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecrutementTest extends TestCase
{
    public function test_jobs_public_careers_page_pipeline_interviews_hiring_and_purge(): void
    {
        [$company, , $token] = $this->makeCompany('Boulangerie Le Pétrin');
        $company->forceFill(['slug' => 'le-petrin'])->save();
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $roleName) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@petrin.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        [$nadia, $rhApi] = $mk('Nadia', 'RH / Paie');
        [$dg, $dgApi] = $mk('Serge', 'Chef de projet');
        [, $caisseApi] = $mk('Awa', 'Caissier');
        $push = fn ($client, array $mut) => $client()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid(), 'op' => 'upsert'], $mut)])->assertOk()->json('results');
        $pull = fn ($client) => $client()->getJson('/api/sync/pull?cursor=0')->assertOk()->json('changes');

        // Offre : publication impossible sans description
        $job = $this->uuid();
        $this->assertSame('rejected', $push($rhApi, [['entity' => 'rc_jobs', 'record_id' => $job, 'data' => ['title' => 'Boulanger(ère)', 'status' => 'open']]])[0]['status']);
        $push($rhApi, [['entity' => 'rc_jobs', 'record_id' => $job, 'data' => ['title' => 'Boulanger(ère)', 'status' => 'open', 'location' => 'Cocody Angré', 'contract_type' => 'CDI',
            'description' => "Préparation du pain et des viennoiseries.\nTravail de nuit (3 h – 11 h).", 'requirements' => 'CAP boulangerie ou 2 ans d\'expérience.', 'positions' => 1]]]);

        // Page Emplois publique et candidature avec CV (anti-robots : délai, champ piège, consentement)
        $this->flushHeaders(); // visiteur anonyme (navigateur)
        $this->get('/emplois/le-petrin')->assertOk()->assertSee('Boulanger(ère)')->assertSee('Candidature spontanée');
        $this->get('/emplois/inconnue')->assertNotFound();
        $form = fn (array $o = []) => $o + ['first_name' => 'Ibrahim', 'last_name' => 'Coulibaly', 'phone' => '+225 07 08 09 10 11', 'email' => 'ibrahim@exemple.ci',
            'city' => 'Abobo', 'cover_letter' => 'Cinq ans en boulangerie industrielle.', 'consent' => '1', 't' => time() - 30, 'cv' => UploadedFile::fake()->create('cv.pdf', 120, 'application/pdf')];
        $this->post("/emplois/le-petrin/$job", $form(['t' => time()]))->assertRedirect()->assertSessionHas('error');
        $this->post("/emplois/le-petrin/$job", $form(['website' => 'http://spam']))->assertSessionHasErrors('website');
        $this->post("/emplois/le-petrin/$job", $form(['consent' => null]))->assertSessionHasErrors('consent');
        $this->post("/emplois/le-petrin/$job", $form(['cv' => UploadedFile::fake()->create('cv.exe', 10)]))->assertSessionHas('error');
        $this->post("/emplois/le-petrin/$job", $form())->assertRedirect('/emplois/le-petrin')->assertSessionHas('ok');
        $this->post('/emplois/le-petrin/spontanee', $form(['first_name' => 'Fatou', 'last_name' => 'Diallo', 'email' => 'fatou@exemple.ci', 'phone' => null]))->assertRedirect();
        $apps = collect($pull($rhApi)['rc_applications']['upserts'])->keyBy('first_name');
        $a = $apps['Ibrahim'];
        $this->assertSame(['website', 'new', true, $job], [$a['source'], $a['stage'], $a['has_cv'], $a['job_id']]);
        $this->assertArrayNotHasKey('cv_path', $a);
        $this->assertNull($apps['Fatou']['job_id']);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $nadia->id)->where('title', 'Nouvelle candidature')->exists());

        // Étapes : refus motivé, embauche réservée au bouton « Embaucher »
        $push($rhApi, [['entity' => 'rc_applications', 'record_id' => $a['id'], 'data' => ['stage' => 'screening', 'rating' => 4]]]);
        $this->assertSame('rejected', $push($rhApi, [['entity' => 'rc_applications', 'record_id' => $apps['Fatou']['id'], 'data' => ['stage' => 'rejected']]])[0]['status']);
        $push($rhApi, [['entity' => 'rc_applications', 'record_id' => $apps['Fatou']['id'], 'data' => ['stage' => 'rejected', 'reject_reason' => 'Profil hors besoin actuel']]]);
        $this->assertSame('rejected', $push($rhApi, [['entity' => 'rc_applications', 'record_id' => $a['id'], 'data' => ['stage' => 'hired']]])[0]['status']);

        // Entretien avec la direction : le candidat passe en « Entretien », l'intervenant est prévenu et ne voit que lui
        $iv = $this->uuid();
        $push($rhApi, [['entity' => 'rc_interviews', 'record_id' => $iv, 'data' => ['application_id' => $a['id'], 'starts_at' => now()->addDay()->setTime(10, 0)->format('Y-m-d H:i:s'), 'location' => 'Bureau Angré', 'interviewer_ids' => [$dg->id, $nadia->id]]]]);
        $this->assertSame('interview', Application::find($a['id'])->stage);
        $this->assertSame(['planned', 45], [collect($pull($rhApi)['rc_interviews']['upserts'])->firstWhere('id', $iv)['status'], collect($pull($rhApi)['rc_interviews']['upserts'])->firstWhere('id', $iv)['duration']]);
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $dg->id)->where('title', 'Entretien de recrutement')->exists());
        $this->assertSame([$a['id']], array_column($pull($dgApi)['rc_applications']['upserts'], 'id'));
        $dgApi()->get("/api/recrutement/applications/{$a['id']}/cv")->assertOk();
        $dgApi()->get("/api/recrutement/applications/{$apps['Fatou']['id']}/cv")->assertNotFound();
        $caisseApi()->get("/api/recrutement/applications/{$a['id']}/cv")->assertForbidden();
        $this->assertContains('Serge', array_column($rhApi()->getJson('/api/recrutement/users')->assertOk()->json('data'), 'name'));
        $dgApi()->getJson('/api/recrutement/users')->assertForbidden();
        // L'intervenant donne son avis (note obligatoire) mais ne replanifie pas
        $this->assertSame('rejected', $push($dgApi, [['entity' => 'rc_interviews', 'record_id' => $iv, 'data' => ['status' => 'done']]])[0]['status']);
        $r = $push($dgApi, [['entity' => 'rc_interviews', 'record_id' => $iv, 'data' => ['status' => 'done', 'score' => 5, 'recommendation' => 'yes', 'feedback' => 'Très bon savoir-faire, disponible de nuit.', 'location' => 'ailleurs']]])[0];
        $this->assertSame(['done', 5, 'Bureau Angré'], [$r['record']['status'], $r['record']['score'], $r['record']['location']]);
        $this->assertSame('rejected', $push($dgApi, [['entity' => 'rc_interviews', 'record_id' => $this->uuid(), 'data' => ['application_id' => $a['id'], 'starts_at' => now()->addDays(2)->format('Y-m-d H:i:s'), 'interviewer_ids' => [$dg->id]]]])[0]['status']);

        // Message au candidat : sans canal configuré, refus explicite
        $rhApi()->postJson("/api/recrutement/applications/{$a['id']}/message", ['text' => 'Bonjour, vous êtes convoqué…'])->assertStatus(422)->assertJsonFragment(['message' => 'Aucun canal disponible pour joindre ce candidat (configurez l\'e-mail, WhatsApp ou les SMS dans les paramètres de messagerie, et vérifiez ses coordonnées).']);

        // Embauche : fiche salarié créée, offre close (1 poste), pas de double embauche
        $push($rhApi, [['entity' => 'rc_applications', 'record_id' => $a['id'], 'data' => ['stage' => 'offer']]]);
        $dgApi()->postJson("/api/recrutement/applications/{$a['id']}/hire", [])->assertForbidden();
        $h = $rhApi()->postJson("/api/recrutement/applications/{$a['id']}/hire", ['hire_date' => now()->addWeek()->toDateString(), 'base_salary' => 140000])->assertOk()->json('data');
        $emp = collect($pull($rhApi)['rh_employees']['upserts'])->firstWhere('id', $h['employee_id']);
        $this->assertSame(['Ibrahim', 'Coulibaly', 'Boulanger(ère)', 'CDI', 140000.0], [$emp['first_name'], $emp['last_name'], $emp['job_title'], $emp['contract_type'], (float) $emp['base_salary']]);
        $this->assertSame('hired', $h['application']['stage']);
        $this->assertSame('closed', collect($pull($rhApi)['rc_jobs']['upserts'])->firstWhere('id', $job)['status']);
        $rhApi()->postJson("/api/recrutement/applications/{$a['id']}/hire", [])->assertStatus(422);
        $this->assertSame('rejected', $rhApi()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'rc_applications', 'op' => 'delete', 'record_id' => $a['id']]]])->json('results.0.status'));
        $this->flushHeaders();
        $this->get('/emplois/le-petrin')->assertOk()->assertDontSee('Boulanger(ère)');

        // Statistiques
        $st = $rhApi()->getJson('/api/recrutement/stats')->assertOk()->json('data');
        $this->assertSame([2, 1, 1], [$st['total'], $st['by_stage']['hired'], $st['by_stage']['rejected']]);
        $this->assertSame(2, $st['by_source']['website']);
        $this->assertSame(1, $st['jobs'][0]['hired']);
        $this->assertSame('le-petrin', basename($rhApi()->getJson('/api/recrutement/settings')->json('data.url')));

        // Conservation des données : candidature non retenue effacée (CV compris) après 24 mois
        $fatou = Application::find($apps['Fatou']['id']);
        $path = Application::withoutGlobalScopes()->where('id', $fatou->id)->value('cv_path');
        $this->assertTrue(Storage::disk('local')->exists($path));
        Application::withoutGlobalScopes()->where('id', $fatou->id)->update(['updated_at' => now()->subMonths(25)]);
        $this->artisan('erp:recrutement-purge')->assertSuccessful();
        $gone = Application::withoutGlobalScopes()->withTrashed()->find($fatou->id);
        $this->assertTrue($gone->trashed());
        $this->assertSame(['Candidat', 'effacé', null, null], [$gone->first_name, $gone->last_name, $gone->email, $gone->cover_letter]);
        $this->assertFalse(Storage::disk('local')->exists($path));
    }
}
