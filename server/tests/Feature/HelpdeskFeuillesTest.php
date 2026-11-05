<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Feuilles\Models\Week;
use App\Modules\Helpdesk\HelpdeskService;
use App\Modules\Helpdesk\Models\Article;
use App\Modules\Helpdesk\Models\Category;
use App\Modules\Helpdesk\Models\Message;
use App\Modules\Helpdesk\Models\SlaPolicy;
use App\Modules\Helpdesk\Models\Ticket;
use App\Modules\Prestations\Models\Entry;
use App\Modules\Prestations\Models\Pack;
use Carbon\Carbon;
use Tests\TestCase;

class HelpdeskFeuillesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Infogérance Pro');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null, ?callable $as = null) => ($as ?? $api)()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        $del = fn (string $entity, string $id, ?callable $as = null) => ($as ?? $api)()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'delete', 'record_id' => $id,
        ]]])->assertOk()->json('results.0');
        $client = fn (string $name, array $extra = []) => $api()->postJson('/api/core/partners', ['name' => $name, 'type' => 'customer'] + $extra)->json('data.id');
        $role = fn ($n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $user = function (string $name, string $r) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)).'@infog.ci', 'password' => 'secret123', 'role_id' => $role($r)]);
            $t = $u->createToken('t')->plainTextToken;

            return [$u, fn () => $this->api($t)];
        };

        return compact('company', 'admin', 'api', 'push', 'del', 'client', 'user');
    }

    private function ok(array $r): array
    {
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $r['record'] ?? [];
    }

    public function test_helpdesk_tickets_sla_time_on_pack_customer_replies_and_help_center(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-01 17:00:00')); // lundi
        ['api' => $api, 'push' => $push, 'del' => $del, 'client' => $client, 'user' => $user, 'company' => $company, 'admin' => $admin] = $this->env();
        [$awa, $awaApi] = $user('Awa Support', 'Technicien');
        [$kofi] = $user('Kofi Support', 'Technicien');
        $this->assertTrue(SlaPolicy::where('is_default', true)->exists());
        $this->assertSame(4, Category::count());
        $panne = Category::where('name', 'Panne ou incident')->first();
        $this->ok($push('hd_categories', ['name' => 'Panne ou incident', 'assignee_id' => $awa->id], $panne->id));
        $acme = $client('Pharmacie du Plateau', ['email' => 'contact@pharma.ci', 'phone' => '+225 07 00 00 00 01']);
        $pack = $this->ok($push('pr_packs', ['partner_id' => $acme, 'name' => 'Support 10 h', 'hours' => 10, 'start_date' => '2027-02-01']));

        // Ticket urgent : responsable de la catégorie, forfait du client, délais en heures ouvrées (8 h – 18 h)
        $tid = $this->uuid();
        $t = $this->ok($push('hd_tickets', ['partner_id' => $acme, 'subject' => 'Caisse bloquée', 'description' => 'Le logiciel de caisse ne démarre plus.', 'priority' => 'urgent', 'channel' => 'phone', 'category_id' => $panne->id], $tid));
        $tk = Ticket::find($tid);
        $this->assertSame('SUP-2027-00001', $tk->number);
        $this->assertEquals([$awa->id, $pack['id'], 'new', 'phone', 'contact@pharma.ci'], [$tk->assignee_id, $tk->pack_id, $tk->status, $tk->channel, $tk->contact_email]);
        $this->assertSame('2027-03-01 17:30', $tk->first_response_due->format('Y-m-d H:i'));
        $this->assertSame('2027-03-02 11:00', $tk->resolution_due->format('Y-m-d H:i'));
        $this->assertSame(1, AppNotification::where('user_id', $awa->id)->where('type', 'helpdesk')->count());
        // Canal « formulaire » réservé au serveur, ticket sans contact refusé, forfait d'un autre client refusé
        $this->assertSame('agent', $this->ok($push('hd_tickets', ['partner_id' => $acme, 'subject' => 'Test', 'channel' => 'web']))['channel']);
        $this->assertStringContainsString('recontacter', $push('hd_tickets', ['subject' => 'Sans contact'])['message']);
        $other = $client('Autre');
        $this->assertStringContainsString('forfait doit appartenir', $push('hd_tickets', ['partner_id' => $other, 'subject' => 'X', 'pack_id' => $pack['id']])['message']);

        // Note interne puis réponse publique de l'agent
        Carbon::setTestNow(Carbon::parse('2027-03-01 17:10:00'));
        $this->ok($push('hd_messages', ['ticket_id' => $tid, 'body' => 'Voir avec le fournisseur du logiciel.', 'internal' => true], null, $awaApi));
        $this->assertNull(Ticket::find($tid)->first_response_at);
        $mid = $this->uuid();
        $this->ok($push('hd_messages', ['ticket_id' => $tid, 'body' => 'Bonjour, redémarrez le poste en maintenant Maj enfoncée.'], $mid, $awaApi));
        $tk = Ticket::find($tid);
        $this->assertEquals(['open', false], [$tk->status, $tk->sla_first_breached]);
        $this->assertSame('2027-03-01 17:10', $tk->first_response_at->format('Y-m-d H:i'));
        $this->assertSame('Awa Support', Message::find($mid)->author_name);
        $this->assertStringContainsString('ne se modifie plus', $push('hd_messages', ['body' => 'modifié'], $mid, $awaApi)['message']);

        // Temps passé décompté du forfait
        $time = $this->uuid();
        $this->ok($push('hd_times', ['ticket_id' => $tid, 'date' => '2027-03-01', 'minutes' => 45, 'note' => 'Diagnostic à distance'], $time, $awaApi));
        $e = Entry::where('ticket_time_id', $time)->first();
        $this->assertEquals([0.75, 'Ticket SUP-2027-00001 — Diagnostic à distance'], [(float) $e->hours, $e->description]);
        $this->ok($push('hd_times', ['minutes' => 90], $time, $awaApi));
        $this->assertEquals(1.5, Pack::find($pack['id'])->consumed());
        $this->assertStringContainsString('ticket', $push('pr_entries', ['hours' => 1], $e->id)['message']);
        $this->assertSame('rejected', $del('pr_entries', $e->id)['status']);
        $this->ok($push('hd_times', ['ticket_id' => $tid, 'date' => '2027-03-01', 'minutes' => 30, 'billable' => false], null, $awaApi));
        $this->assertEquals(1.5, Pack::find($pack['id'])->consumed());
        $this->assertSame(120, Ticket::find($tid)->minutesSpent());

        // En attente du client : le délai de résolution est suspendu ; le client répond depuis son lien
        Carbon::setTestNow(Carbon::parse('2027-03-02 09:00:00'));
        $this->ok($push('hd_tickets', ['status' => 'pending'], $tid, $awaApi));
        Carbon::setTestNow(Carbon::parse('2027-03-02 10:00:00'));
        $this->flushHeaders();
        $this->get('/support/'.$tk->token)->assertOk()->assertSee('Caisse bloquée')->assertSee('redémarrez le poste')->assertDontSee('fournisseur du logiciel')->assertSee('Nous attendons votre réponse');
        $this->post('/support/'.$tk->token, ['body' => 'Toujours bloqué, écran noir.'])->assertRedirect();
        $tk = Ticket::find($tid);
        $this->assertEquals(['open', 60], [$tk->status, $tk->paused_minutes]);
        $this->assertSame('2027-03-02 12:00', $tk->resolution_due->format('Y-m-d H:i'));
        $this->assertSame('customer', Message::where('ticket_id', $tid)->where('author_type', 'customer')->first()->author_type);
        $this->assertTrue(AppNotification::where('user_id', $awa->id)->where('title', 'like', 'Réponse du client%')->exists());
        $this->post('/support/'.$tk->token, ['body' => 'spam', 'website' => 'http://x'])->assertSessionHasErrors('website');

        // Résolu (dans les délais), avis du client, réouverture par une réponse
        Carbon::setTestNow(Carbon::parse('2027-03-02 11:30:00'));
        $this->ok($push('hd_tickets', ['status' => 'resolved'], $tid, $awaApi));
        $tk = Ticket::find($tid);
        $this->assertEquals([false, '2027-03-02 11:30'], [$tk->sla_resolve_breached, $tk->resolved_at->format('Y-m-d H:i')]);
        $this->flushHeaders();
        $this->post('/support/'.$tk->token.'/avis', ['rating' => 2, 'comment' => 'Trop long'])->assertRedirect();
        $this->assertEquals(2, Ticket::find($tid)->rating);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Client mécontent%')->exists());
        $this->post('/support/'.$tk->token, ['body' => 'Le problème revient.'])->assertRedirect();
        $tk = Ticket::find($tid);
        $this->assertEquals(['open', 1, null], [$tk->status, $tk->reopened, $tk->rating]);
        $this->ok($push('hd_tickets', ['status' => 'resolved'], $tid, $awaApi));

        // Délais dépassés signalés une fois, ticket résolu fermé après 5 jours
        $t2 = $this->uuid();
        $this->ok($push('hd_tickets', ['partner_id' => $acme, 'subject' => 'Imprimante', 'priority' => 'normal'], $t2));
        Carbon::setTestNow(Carbon::parse('2027-03-08 12:00:00'));
        app()->instance('tenant', $company->fresh());
        $r = app(HelpdeskService::class)->runEvery($company->fresh());
        $this->assertEquals(['breached' => 4, 'closed' => 1], $r); // ticket « Imprimante » et ticket « Test » : réponse et résolution
        $this->assertTrue(Ticket::find($t2)->sla_first_breached);
        $this->assertSame('closed', Ticket::find($tid)->status);
        $this->assertEquals(['breached' => 0, 'closed' => 0], app(HelpdeskService::class)->runEvery($company->fresh()));
        $this->assertStringContainsString('fermé', $push('hd_messages', ['ticket_id' => $tid, 'body' => 'Bonjour'], null, $awaApi)['message']);
        $s = $api()->getJson('/api/helpdesk/stats')->assertOk()->json('data');
        $this->assertEquals([2, 2], [$s['open'], $s['overdue']]);
        $this->assertSame(1, collect($s['by_channel'])->firstWhere('channel', 'phone')['count']);

        // Centre d'aide : articles publiés, recherche, vote ; formulaire de contact
        $this->ok($push('hd_articles', ['title' => 'Réinitialiser le mot de passe de la caisse', 'section' => 'Caisse', 'body' => "## Étapes\n- Ouvrez **Paramètres**\n- Cliquez sur Mot de passe", 'published' => true]));
        $this->ok($push('hd_articles', ['title' => 'Brouillon interne', 'body' => 'Mot de passe administrateur', 'published' => false]));
        $this->assertStringContainsString('<b>Paramètres</b>', HelpdeskService::render("- Ouvrez **Paramètres**"));
        $slug = $company->slug;
        $this->flushHeaders();
        $this->get('/aide/'.$slug)->assertOk()->assertSee('Réinitialiser le mot de passe')->assertDontSee('Brouillon interne')->assertHeader('X-Robots-Tag', 'index, follow');
        $this->get('/aide/'.$slug.'?q=mot+de+passe')->assertOk()->assertSee('1 résultat(s)', false);
        $a = Article::where('published', true)->first();
        $this->get('/aide/'.$slug.'/a/'.$a->slug)->assertOk()->assertSee('<li>Ouvrez <b>Paramètres</b></li>', false);
        $this->assertSame(1, Article::find($a->id)->views);
        $this->post('/aide/'.$slug.'/a/'.$a->slug.'/vote', ['helpful' => 'yes'])->assertRedirect();
        $this->post('/aide/'.$slug.'/a/'.$a->slug.'/vote', ['helpful' => 'yes'])->assertRedirect();
        $this->assertSame(1, Article::find($a->id)->helpful_yes);
        $this->get('/aide/'.$slug.'/a/brouillon-interne')->assertNotFound();
        $this->get('/aide/'.$slug.'/contact?q=mot+de+passe')->assertOk()->assertSee('répondent peut-être', false);
        $this->post('/aide/'.$slug.'/contact', ['name' => 'Bot', 'email' => 'b@x.ci', 'subject' => 'X', 'description' => 'Y', 'website' => 'spam'])->assertSessionHasErrors('website');
        $this->post('/aide/'.$slug.'/contact', ['name' => 'Nadia Koné', 'subject' => 'Sans contact', 'description' => 'Y'])->assertSessionHasErrors('email');
        $res = $this->post('/aide/'.$slug.'/contact', ['name' => 'Nadia Koné', 'email' => 'nadia@boutique.ci', 'subject' => 'Facture en double', 'description' => 'J\'ai reçu deux fois la facture.', 'priority' => 'high']);
        $web = Ticket::where('subject', 'Facture en double')->first();
        $res->assertRedirect('/support/'.$web->token);
        $this->assertEquals(['web', 'high', 'Nadia Koné'], [$web->channel, $web->priority, $web->partner_name]);
        // Espace client : onglet Support
        $url = $api()->postJson("/api/portail/partners/$acme/invite", ['channel' => 'none'])->json('url');
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get(parse_url($url, PHP_URL_PATH).'/support')->assertOk()->assertSee('Caisse bloquée')->assertSee('Imprimante');
        // Réglages
        $api()->putJson('/api/helpdesk/settings', ['hours' => ['start' => '18:00', 'end' => '08:00']])->assertStatus(422);
        $api()->putJson('/api/helpdesk/settings', ['contact_form' => false])->assertOk();
        $this->get('/aide/'.$slug.'/contact')->assertNotFound();
        $awaApi()->putJson('/api/helpdesk/settings', ['contact_form' => true])->assertForbidden();
    }

    public function test_weekly_timesheets_submit_lock_review_and_invoice_only_approved_time(): void
    {
        Carbon::setTestNow(Carbon::parse('2027-03-10 15:00:00')); // mercredi
        ['api' => $api, 'push' => $push, 'del' => $del, 'client' => $client, 'user' => $user, 'company' => $company, 'admin' => $admin] = $this->env();
        [$kofi, $kofiApi] = $user('Kofi Dev', 'Équipe projet');
        [$ama, $amaApi] = $user('Ama Graphiste', 'Équipe projet');
        $r = Role::find($kofi->role_id);
        $r->permissions = array_merge($r->permissions, ['helpdesk.agent']);
        $r->save();
        $acme = $client('Banque Atlantique');
        $prj = $this->uuid();
        $this->ok($push('prj_projects', ['name' => 'Application mobile', 'partner_id' => $acme, 'billing_mode' => 'time', 'hourly_rate' => 20000, 'member_ids' => [$kofi->id]], $prj));
        $tsPrev = $this->ok($push('prj_timesheets', ['project_id' => $prj, 'user_id' => $kofi->id, 'date' => '2027-03-03', 'hours' => 3, 'billable' => true]));
        $ts1 = $this->uuid();
        $this->ok($push('prj_timesheets', ['project_id' => $prj, 'date' => '2027-03-08', 'hours' => 6, 'description' => 'Écran de connexion', 'billable' => true], $ts1, $kofiApi));
        $this->ok($push('prj_timesheets', ['project_id' => $prj, 'date' => '2027-03-09', 'hours' => 4, 'billable' => true], null, $kofiApi));
        $pack = $this->ok($push('pr_packs', ['partner_id' => $acme, 'name' => 'Maintenance', 'hours' => 20, 'start_date' => '2027-03-01']));
        $this->ok($push('pr_entries', ['pack_id' => $pack['id'], 'date' => '2027-03-09', 'hours' => 1.5, 'description' => 'Mise à jour serveur'], null, $kofiApi));
        $tk = $this->ok($push('hd_tickets', ['partner_id' => $acme, 'subject' => 'Bug connexion']));
        $this->ok($push('hd_times', ['ticket_id' => $tk['id'], 'date' => '2027-03-10', 'minutes' => 30], null, $kofiApi));
        // Le temps du ticket recopié sur le forfait n'est pas compté deux fois
        $w = $kofiApi()->getJson('/api/feuilles_temps/week?week=2027-03-10')->assertOk()->json('data');
        $this->assertEquals([12, 4], [$w['total'], count($w['entries'])]);
        $this->assertEquals(['project', 'project', 'pack', 'ticket'], array_column($w['entries'], 'source'));
        $this->assertEquals(6, $w['days']['2027-03-08']);
        $kofiApi()->getJson('/api/feuilles_temps/week?user_id='.$ama->id)->assertForbidden();

        // Envoi : semaine verrouillée, responsables prévenus
        $wid = $this->uuid();
        $r = $this->ok($push('tt_weeks', ['week_start' => '2027-03-10', 'status' => 'submitted'], $wid, $kofiApi));
        $this->assertEquals(['2027-03-08', 12, 'submitted'], [$r['week_start'], $r['hours'], $r['status']]);
        $this->assertTrue(AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Feuille de temps à valider%')->exists());
        $this->assertStringContainsString('envoyée pour validation', $push('prj_timesheets', ['project_id' => $prj, 'date' => '2027-03-11', 'hours' => 2], null, $kofiApi)['message']);
        $this->assertStringContainsString('envoyée pour validation', $push('prj_timesheets', ['hours' => 7], $ts1, $kofiApi)['message']);
        $this->assertSame('rejected', $del('prj_timesheets', $ts1, $kofiApi)['status']);
        $this->assertStringContainsString('envoyée pour validation', $push('hd_times', ['ticket_id' => $tk['id'], 'date' => '2027-03-11', 'minutes' => 10], null, $kofiApi)['message']);
        $this->assertStringContainsString('envoyée pour validation', $push('pr_entries', ['pack_id' => $pack['id'], 'date' => '2027-03-08', 'hours' => 1, 'description' => 'x'], null, $kofiApi)['message']);
        $this->ok($push('prj_timesheets', ['project_id' => $prj, 'date' => '2027-03-15', 'hours' => 2], null, $kofiApi)); // semaine suivante libre
        $this->assertStringContainsString('existe déjà', $push('tt_weeks', ['week_start' => '2027-03-09'], null, $kofiApi)['message']);
        // Pas d'auto-validation ; Ama ne voit pas la feuille de Kofi
        $this->assertStringContainsString('propre feuille', $push('tt_weeks', ['status' => 'approved'], $wid, $kofiApi)['message']);
        $amaApi()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $this->assertNotContains($wid, collect($amaApi()->getJson('/api/sync/pull?cursor=0')->json('changes'))->where('entity', 'tt_weeks')->pluck('record_id')->all());
        $kofiApi()->getJson('/api/feuilles_temps/pending')->assertForbidden();

        // Retrait, correction, nouvel envoi ; renvoi motivé ; validation
        $this->ok($push('tt_weeks', ['status' => 'draft'], $wid, $kofiApi));
        $this->ok($push('prj_timesheets', ['hours' => 7], $ts1, $kofiApi));
        $this->ok($push('tt_weeks', ['status' => 'submitted'], $wid, $kofiApi));
        $p = $api()->getJson('/api/feuilles_temps/pending')->assertOk()->json('data');
        $this->assertEquals([13, 'Kofi Dev'], [$p[0]['total'], $p[0]['week']['user_name']]);
        $this->assertStringContainsString('Expliquez', $push('tt_weeks', ['status' => 'rejected'], $wid)['message']);
        $this->ok($push('tt_weeks', ['status' => 'rejected', 'comment' => 'Précisez la tâche du mardi.'], $wid));
        $this->assertTrue(AppNotification::where('user_id', $kofi->id)->where('title', 'Feuille de temps à corriger')->exists());
        $this->ok($push('tt_weeks', ['status' => 'submitted'], $wid, $kofiApi));
        $this->ok($push('tt_weeks', ['status' => 'approved'], $wid));
        $wk = Week::find($wid);
        $this->assertEquals(['approved', $admin->name, 6], [$wk->status, $wk->decided_by, count($wk->history)]);
        $this->assertStringContainsString('seul un responsable', $push('tt_weeks', ['status' => 'draft'], $wid, $kofiApi)['message']);
        $this->assertStringContainsString('déjà validée', $push('prj_timesheets', ['hours' => 8], $ts1, $kofiApi)['message']);

        // Facturation : seul le temps validé quand l'option est active
        $api()->putJson('/api/feuilles_temps/settings', ['require_approval' => true])->assertOk();
        $inv = $api()->postJson('/api/projets/projects/'.$prj.'/invoice', [])->assertOk()->json('invoice');
        $this->assertEquals(11, collect($inv['lines'])->sum('quantity')); // 7 + 4, pas la semaine du 1er ni celle du 15
        $api()->postJson('/api/projets/projects/'.$prj.'/invoice', [])->assertStatus(422)->assertJsonPath('message', 'Aucun temps validé à facturer : 2 saisie(s) attendent la validation des feuilles de temps.');
        // Réouverture par le responsable
        $this->ok($push('tt_weeks', ['status' => 'draft'], $wid));
        $this->assertTrue(AppNotification::where('user_id', $kofi->id)->where('title', 'Feuille de temps rouverte')->exists());
        $this->assertSame('rejected', $del('tt_weeks', $wid)['status']);
        $this->assertNotNull($tsPrev);
    }
}
