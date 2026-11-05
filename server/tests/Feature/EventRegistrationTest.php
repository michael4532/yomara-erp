<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Modules\Billetterie\Models\Ticket;
use App\Modules\Billetterie\Models\TicketOrder;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Evenements\Models\Event;
use App\Modules\Marketing\Models\Contact;
use App\Modules\Marketing\Models\MkList;
use App\Modules\Ventes\Models\SalesDocument;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EventRegistrationTest extends TestCase
{
    protected ArrayTransport $mails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mails = new ArrayTransport;
        Smtp::$transportFactory = fn () => $this->mails;
    }

    protected function tearDown(): void
    {
        Smtp::$transportFactory = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function env(): array
    {
        [$company, $admin, $token] = $this->makeCompany('Agence Kanvas');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $push = fn (string $entity, array $data, ?string $id = null) => $api()->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => $entity, 'op' => 'upsert', 'record_id' => $id ?? $this->uuid(), 'data' => $data,
        ]]])->assertOk()->json('results.0');
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.k.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'e@k.ci', 'password' => 'x', 'from_email' => 'e@k.ci', 'from_name' => 'Kanvas',
        ]])->assertOk();

        return compact('company', 'admin', 'token', 'api', 'push');
    }

    private function ok(array $r): array
    {
        $this->assertSame('applied', $r['status'], json_encode($r));

        return $r['record'];
    }

    private function mailsTo(string $email): array
    {
        return collect($this->mails->messages()->values())->map->getOriginalMessage()->filter(fn ($m) => $m->getTo()[0]->getAddress() === $email)->values()->all();
    }

    private function public(): void
    {
        $this->flushHeaders();
        app()->forgetInstance('tenant');
    }

    public function test_free_registration_form_qr_email_marketing_lists_feedback_and_analytics(): void
    {
        Carbon::setTestNow('2026-11-02 09:00:00');
        ['api' => $api, 'push' => $push] = $this->env();
        $ev = $this->uuid();
        $r = $this->ok($push('ev_events', ['name' => 'Forum Digital Abidjan', 'venue' => 'Sofitel', 'city' => 'Abidjan', 'start_date' => '2026-11-20', 'end_date' => '2026-11-21', 'status' => 'open',
            'ticketing_enabled' => true, 'registration' => ['ask_job' => true, 'questions' => [
                ['label' => 'Votre secteur', 'type' => 'select', 'required' => true, 'options' => ['Banque', 'Télécoms', ' ', 'Commerce']],
                ['label' => 'Vos attentes', 'type' => 'textarea'], ['label' => '', 'type' => 'text'], ['label' => 'Pirate', 'type' => 'script'],
            ], 'consent_text' => 'J\'accepte les infos de {societe}.']], $ev));
        // Formulaire nettoyé : questions sans libellé retirées, type inconnu ramené à « texte », options vides supprimées
        $q = $r['registration']['questions'];
        $this->assertSame(['Votre secteur', 'Vos attentes', 'Pirate'], array_column($q, 'label'));
        $this->assertSame('text', $q[2]['type']);
        $this->assertSame(['Banque', 'Télécoms', 'Commerce'], $q[0]['options']);
        $this->assertSame('required', $r['registration']['ask_email']);
        $free = $this->ok($push('ev_ticket_types', ['event_id' => $ev, 'name' => 'Entrée libre', 'price' => 0]));
        $tokenUrl = '/billetterie/'.Event::find($ev)->public_token;

        // Page publique : bouton « Je m'inscris », champs entreprise / fonction / questions / consentement
        $this->public();
        $this->get($tokenUrl.'?utm_source=facebook')->assertOk()->assertSee('Je m\'inscris')->assertSee('Votre secteur')->assertSee('Fonction')->assertSee('J\'accepte les infos de Agence Kanvas.')->assertSee('value="facebook"', false);
        // Question obligatoire et choix hors liste refusés
        $this->post($tokenUrl, ['name' => 'Awa Koné', 'qty' => [$free['id'] => 1]])->assertSessionHasErrors('email');
        $this->post($tokenUrl, ['name' => 'Awa Koné', 'email' => 'awa@banque.ci', 'qty' => [$free['id'] => 1]])->assertSessionHas('error');
        $this->post($tokenUrl, ['name' => 'Awa Koné', 'email' => 'awa@banque.ci', 'qty' => [$free['id'] => 1], 'q' => [$q[0]['id'] => 'Pêche']])->assertSessionHas('error');
        $this->assertSame(0, TicketOrder::count());
        $this->post($tokenUrl, ['name' => 'Awa Koné', 'phone' => '07 07 11 22 33', 'email' => 'Awa@Banque.ci', 'company' => 'Banque Atlantique', 'job' => 'DRH',
            'qty' => [$free['id'] => 1], 'q' => [$q[0]['id'] => 'Banque', $q[1]['id'] => 'Rencontrer des fintechs'], 'consent' => '1', 'source' => 'facebook'])->assertRedirect();
        $o = TicketOrder::withoutGlobalScopes()->first();
        $this->assertEquals(['paid', 'free', 'Banque Atlantique', 'DRH', true, 'facebook'], [$o->status, $o->payment_method, $o->buyer_company, $o->buyer_job, $o->consent, $o->source]);
        $this->assertSame([['id' => $q[0]['id'], 'label' => 'Votre secteur', 'value' => 'Banque'], ['id' => $q[1]['id'], 'label' => 'Vos attentes', 'value' => 'Rencontrer des fintechs']], $o->answers);
        $t = Ticket::withoutGlobalScopes()->where('order_id', $o->id)->first();
        $this->assertSame('Banque Atlantique', $t->company_name);

        // E-mail de confirmation : QR code dans le corps, billets PDF et agenda en pièces jointes
        $m = $this->mailsTo('Awa@Banque.ci') ?: $this->mailsTo('awa@banque.ci');
        $this->assertCount(1, $m);
        $this->assertSame('Inscription confirmée — Forum Digital Abidjan', $m[0]->getSubject());
        $this->assertStringContainsString($t->code, $m[0]->getHtmlBody());
        $this->assertStringContainsString('data:image/svg+xml;base64', $m[0]->getHtmlBody());
        $this->assertStringContainsString('Rencontrer des fintechs', $m[0]->getHtmlBody());
        $att = collect($m[0]->getAttachments())->map(fn ($a) => $a->getContentType())->all();
        $this->assertSame(['application/pdf', 'text/calendar'], $att);
        $this->assertStringStartsWith('%PDF', $m[0]->getAttachments()[0]->getBody());

        // Contacts Marketing : liste « Inscrits — … » + liste générale (consentement), étiquette, entreprise
        $c = Contact::withoutGlobalScopes()->where('email', 'awa@banque.ci')->first();
        $this->assertNotNull($c);
        $this->assertEquals(['Awa', 'Koné', 'Banque Atlantique', 'confirmed'], [$c->first_name, $c->last_name, $c->company_name, $c->opt_in]);
        $this->assertStringContainsString('événement', $c->tags);
        $lists = MkList::withoutGlobalScopes()->whereIn('id', DB::table('mk_list_contact')->where('contact_id', $c->id)->pluck('list_id'))->pluck('name')->sort()->values()->all();
        $this->assertSame(['Abonnés événements', 'Inscrits — Forum Digital Abidjan'], $lists);
        $this->assertSame(Event::withoutGlobalScopes()->find($ev)->mk_list_id, MkList::withoutGlobalScopes()->where('name', 'like', 'Inscrits%')->value('id'));

        // Deuxième inscription, même personne retrouvée par téléphone ; sans consentement → seulement la liste de l'événement
        $this->post($tokenUrl, ['name' => 'Awa K', 'phone' => '+225 0707112233', 'email' => 'awa.kone@gmail.com', 'qty' => [$free['id'] => 2], 'q' => [$q[0]['id'] => 'Banque']])->assertRedirect();
        $this->post($tokenUrl, ['name' => 'Jean Yao', 'email' => 'jean@orange.ci', 'qty' => [$free['id'] => 1], 'q' => [$q[0]['id'] => 'Télécoms'], 'company' => 'Orange CI'])->assertRedirect();
        $this->assertSame(2, Contact::withoutGlobalScopes()->count());
        $jean = Contact::withoutGlobalScopes()->where('email', 'jean@orange.ci')->first();
        $this->assertSame(1, DB::table('mk_list_contact')->where('contact_id', $jean->id)->count());
        $this->assertSame(4, Ticket::withoutGlobalScopes()->where('event_id', $ev)->count());

        // Analyse des inscriptions
        $a = $api()->getJson("/api/billetterie/events/$ev/analytics")->assertOk()->json('data');
        $this->assertEquals([3, 4, 3, 2], [$a['registrations'], $a['tickets'], $a['free'], $a['list_contacts']]);
        $this->assertSame(1, $a['consent']);
        $this->assertSame([['value' => 'Banque', 'count' => 2], ['value' => 'Télécoms', 'count' => 1]], $a['answers'][0]['distribution']);
        $this->assertSame(['Rencontrer des fintechs'], $a['answers'][1]['samples']);
        $this->assertSame('facebook', $a['sources'][0]['source'] === 'facebook' ? 'facebook' : $a['sources'][1]['source']);
        $this->assertEquals(4, collect($a['by_day'])->last()['cumulative']);
        $this->assertSame(['Banque Atlantique', 'Orange CI'], collect($a['top_companies'])->pluck('name')->sort()->values()->all());

        // Avis : refusé avant la fin, puis tâche quotidienne le lendemain de l'événement (une seule fois par personne)
        $api()->postJson("/api/billetterie/events/$ev/feedback-request")->assertStatus(422);
        Ticket::withoutGlobalScopes()->where('id', $t->id)->update(['checked_in_at' => '2026-11-20 10:15:00']);
        Carbon::setTestNow('2026-11-22 10:05:00');
        $before = count($this->mails->messages());
        $this->artisan('erp:event-feedback')->assertSuccessful();
        $this->artisan('erp:event-feedback')->assertSuccessful();
        $asks = collect($this->mails->messages()->values())->slice($before)->map->getOriginalMessage();
        $this->assertCount(3, $asks); // Awa (2 commandes, 3 billets : 2 adresses) + Jean
        $this->assertSame('Votre avis sur Forum Digital Abidjan', $asks->first()->getSubject());
        $this->assertSame(1, $asks->filter(fn ($m) => str_contains($m->getHtmlBody(), '/avis/'.$t->token.'?note=5'))->count());
        $api()->postJson("/api/billetterie/events/$ev/feedback-request")->assertStatus(422); // déjà envoyé

        $this->public();
        $this->get('/avis/'.$t->token.'?note=4')->assertOk()->assertSee('Forum Digital Abidjan');
        $this->post('/avis/'.$t->token, ['nps' => 9])->assertSessionHasErrors('rating');
        $this->post('/avis/'.$t->token, ['rating' => 4, 'nps' => 9, 'comment' => 'Très bonne organisation, panels riches.'])->assertRedirect('/avis/'.$t->token);
        $this->post('/avis/'.$t->token, ['rating' => 5, 'nps' => 10, 'comment' => 'Excellent !'])->assertRedirect(); // modification, pas de doublon
        $jt = Ticket::withoutGlobalScopes()->where('holder_email', 'jean@orange.ci')->first();
        $this->post('/avis/'.$jt->token, ['rating' => 2, 'nps' => 4])->assertRedirect();
        $this->get('/avis/'.str_repeat('z', 40))->assertNotFound();
        $f = $api()->getJson("/api/billetterie/events/$ev/analytics")->assertOk()->json('data');
        $this->assertEquals([2, 3.5, 0], [$f['feedback']['count'], $f['feedback']['rating'], $f['feedback']['nps']]); // 1 promoteur, 1 détracteur
        $this->assertSame(['Excellent !'], array_column($f['feedback']['comments'], 'comment'));
        $this->assertEquals([1, 1, 25.0], [$f['checked_in'], $f['feedback']['stars'][5], $f['attendance_rate']]);
    }

    public function test_stand_dimensions_plan_state_mark_paid_exhibitor_space_directory_and_admin_overview(): void
    {
        Storage::fake('public');
        ['api' => $api, 'push' => $push, 'company' => $company] = $this->env();
        $ev = $this->uuid();
        $this->ok($push('ev_events', ['name' => 'Salon de l\'Habitat', 'start_date' => now()->addDays(30)->toDateString(), 'end_date' => now()->addDays(32)->toDateString(),
            'status' => 'open', 'public_enabled' => true, 'tax_rate' => 18, 'deposit_percent' => 50, 'plan_cols' => 30, 'plan_rows' => 12], $ev));
        // Dimensions en mètres : surface calculée
        $a1 = $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'A1', 'x' => 1, 'y' => 1, 'w' => 3, 'h' => 3, 'width_m' => 3, 'depth_m' => 2.5, 'price' => 200000]));
        $this->assertEquals([3, 2.5, 7.5], [$a1['width_m'], $a1['depth_m'], $a1['area']]);
        $a2 = $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'A2', 'x' => 5, 'y' => 1, 'w' => 3, 'h' => 3, 'width_m' => 3, 'depth_m' => 3, 'price' => 250000]));
        $a3 = $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'A3', 'x' => 9, 'y' => 1, 'w' => 3, 'h' => 3, 'price' => 250000]));
        $this->ok($push('ev_spaces', ['event_id' => $ev, 'code' => 'BAR', 'x' => 13, 'y' => 1, 'w' => 3, 'h' => 3, 'blocked' => true]));
        $this->assertSame('rejected', $push('ev_spaces', ['event_id' => $ev, 'code' => 'Z', 'x' => 20, 'y' => 5, 'width_m' => -2])['status']);
        $gold = $this->ok($push('ev_packages', ['event_id' => $ev, 'kind' => 'sponsor', 'name' => 'Sponsor Or', 'price' => 1000000]));
        $p1 = $api()->postJson('/api/core/partners', ['name' => 'Céramiques Akwaba', 'type' => 'customer'])->json('data.id');
        $p2 = $api()->postJson('/api/core/partners', ['name' => 'BTP Ivoire', 'type' => 'customer'])->json('data.id');
        $p3 = $api()->postJson('/api/core/partners', ['name' => 'Peintures Lumière', 'type' => 'customer'])->json('data.id');
        $b1 = $this->ok($push('ev_bookings', ['event_id' => $ev, 'partner_id' => $p1, 'space_ids' => [$a1['id']]]))['id'];
        $b2 = $this->ok($push('ev_bookings', ['event_id' => $ev, 'partner_id' => $p2, 'space_ids' => [$a2['id']], 'items' => [['package_id' => $gold['id'], 'quantity' => 1]]]))['id'];
        $b3 = $this->ok($push('ev_bookings', ['event_id' => $ev, 'partner_id' => $p3, 'space_ids' => [$a3['id']]]))['id'];
        $this->ok($push('ev_bookings', ['status' => 'confirmed'], $b2));
        $this->ok($push('ev_bookings', ['status' => 'confirmed'], $b3));

        // Marquer payé : refusé sur une option, puis acompte + solde encaissés en une fois
        $api()->postJson("/api/evenements/bookings/$b1/mark-paid", ['method' => 'cash'])->assertStatus(422);
        $r = $api()->postJson("/api/evenements/bookings/$b2/mark-paid", ['method' => 'mobile_money', 'reference' => 'MP2611'])->assertOk()->json('data');
        $this->assertEquals(1250000 * 1.18, $r['amount']);
        $this->assertSame('paid', $r['booking']['payment_status']);
        $this->assertSame(2, SalesDocument::where('reference', 'like', Booking::find($b2)->number.'%')->where('payment_status', 'paid')->count());
        $api()->postJson("/api/evenements/bookings/$b2/mark-paid", ['method' => 'cash'])->assertStatus(422)->assertJsonPath('message', 'Rien à encaisser : la réservation est déjà soldée.');

        // État du plan : libre, bloqué, option, réservé (acompte dû), payé
        $plan = $api()->getJson("/api/evenements/events/$ev/plan")->assertOk()->json('data');
        $this->assertSame(['free' => 0, 'blocked' => 1, 'option' => 1, 'reserved' => 1, 'partial' => 0, 'paid' => 1], $plan['counts']);
        $s = collect($plan['spaces'])->keyBy('code');
        $this->assertEquals(['option', 'Céramiques Akwaba', 3, 2.5], [$s['A1']['state'], $s['A1']['exhibitor'], $s['A1']['width_m'], $s['A1']['depth_m']]);
        $this->assertEquals(['paid', 'reserved', 'blocked'], [$s['A2']['state'], $s['A3']['state'], $s['BAR']['state']]);

        // Espace exposant : lien dans la réservation, fiche + logo, publication après confirmation seulement
        $bk = Booking::find($b2)->toSyncArray();
        $this->assertStringContainsString('/espace', $bk['space_url']);
        $path = parse_url($bk['space_url'], PHP_URL_PATH);
        $opt = parse_url(Booking::find($b1)->toSyncArray()['space_url'], PHP_URL_PATH);
        $this->public();
        $this->get(substr($path, 0, -7))->assertOk()->assertSee('Présenter mon entreprise');
        $this->get($path)->assertOk()->assertSee('Mon espace')->assertSee('Afficher ma fiche dans l');
        $this->post($path, ['tagline' => 'Bâtir durable', 'description' => 'Entreprise de BTP depuis 1998.', 'website' => 'btp-ivoire.ci', 'offer' => 'Devis gratuit sur le stand', 'sector' => 'Construction',
            'published' => '1', 'logo' => UploadedFile::fake()->image('logo.png', 200, 200)])->assertRedirect($path);
        $b = Booking::withoutGlobalScopes()->find($b2);
        $this->assertTrue($b->profile_published);
        $this->assertSame('https://btp-ivoire.ci', $b->profile['website']);
        Storage::disk('public')->assertExists($b->logo_path);
        $this->post($path, ['logo' => UploadedFile::fake()->create('virus.exe', 10)])->assertSessionHas('error');
        $this->get($opt)->assertOk()->assertSee('dès la confirmation');
        $this->post($opt, ['tagline' => 'Céramique d\'art', 'published' => '1'])->assertRedirect();
        $this->assertFalse(Booking::withoutGlobalScopes()->find($b1)->profile_published);
        $this->get('/salon/'.Event::withoutGlobalScopes()->find($ev)->public_token.'/r/'.str_repeat('x', 40).'/espace')->assertNotFound();

        // Saisie par l'équipe depuis l'ERP
        $api()->postJson("/api/evenements/bookings/$b3/profile", ['tagline' => 'Couleurs d\'Afrique', 'sector' => 'Peinture', 'published' => true])->assertOk()->assertJsonPath('data.profile.tagline', 'Couleurs d\'Afrique');

        // Annuaire public : sponsors en tête avec leur formule, puis exposants ; les fiches non publiées n'y sont pas
        $this->public();
        $dir = $this->get('/salon/'.Event::withoutGlobalScopes()->find($ev)->public_token.'/exposants')->assertOk();
        $dir->assertSeeInOrder(['Partenaires et sponsors', 'BTP Ivoire', 'Sponsor Or', 'Bâtir durable', 'Devis gratuit', 'Exposants', 'Peintures Lumière', 'Couleurs d']);
        $dir->assertDontSee('Céramique d\'art', false);

        // Super admin : vue de tous les événements de la plateforme
        [, , $root] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        $all = $this->api($root)->getJson('/api/admin/events?period=upcoming')->assertOk()->json();
        $row = collect($all['data'])->firstWhere('id', $ev);
        $this->assertEquals(['Agence Kanvas', 4, 3, 75.0, 1, 2], [$row['company'], $row['spaces'], $row['spaces_taken'], $row['fill_rate'], $row['bookings_option'], $row['bookings_confirmed']]);
        $this->assertSame(1, $all['totals']['companies']);
        $api()->getJson('/api/admin/events')->assertForbidden();
    }
}
