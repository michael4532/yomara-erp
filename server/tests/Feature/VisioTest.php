<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Visio\Housekeeping;
use App\Modules\Visio\Models\Meeting;
use App\Modules\Visio\Models\Participant;
use App\Modules\Visio\Tokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Réunions & webinaires : jetons, salle, bascule automatique entre fournisseurs, compte rendu, webinaires. */
class VisioTest extends TestCase
{
    protected const AGORA_ID = '970ca35de60c44645bbae8a215061b33';

    protected const AGORA_CERT = '5cfd2fd1755d40ecb72977518be15d3b';

    protected const ZEGO_SECRET = 'fa94dd0f974cf2e293728a526b028271';

    protected function room(?string $t)
    {
        $this->flushHeaders();

        return $this->withHeaders(['Accept' => 'application/json', 'X-Visio-Token' => (string) $t]);
    }

    public function test_tokens(): void
    {
        $t = Tokens::agora(self::AGORA_ID, self::AGORA_CERT, 'yv42', 7, 3600, true, 1111111, 1);
        $this->assertStringStartsWith('007', $t);
        $d = Tokens::decodeAgora($t);
        $this->assertEquals([self::AGORA_ID, 1111111, 3600, 1, 1, 'yv42', '7'], [$d['app_id'], $d['issued_at'], $d['expire'], $d['salt'], $d['service'], $d['channel'], $d['uid']]);
        $this->assertEquals([1 => 3600, 2 => 3600, 3 => 3600, 4 => 3600], $d['privileges']);
        // Signature : HMAC des données avec la clé dérivée du certificat (émission puis sel)
        $key = hash_hmac('sha256', hash_hmac('sha256', self::AGORA_CERT, pack('V', 1111111), true), pack('V', 1), true);
        $this->assertSame(hash_hmac('sha256', $d['signed_data'], $key, true), $d['signature']);
        $this->assertEquals([1 => 60], Tokens::decodeAgora(Tokens::agora(self::AGORA_ID, self::AGORA_CERT, 'c', 0, 60, false))['privileges']);
        $this->assertSame('', Tokens::decodeAgora(Tokens::agora(self::AGORA_ID, self::AGORA_CERT, 'c', 0, 60))['uid']);

        $z = Tokens::zego(123456789, self::ZEGO_SECRET, 'p5', 600);
        $this->assertStringStartsWith('04', $z);
        $dz = Tokens::decodeZego($z, self::ZEGO_SECRET);
        $this->assertEquals([123456789, 'p5', ''], [$dz['app_id'], $dz['user_id'], $dz['payload']]);
        $this->assertEquals($dz['ctime'] + 600, $dz['expire']);

        $this->expectException(\InvalidArgumentException::class);
        Tokens::agora('court', self::AGORA_CERT, 'c', 1);
    }

    public function test_meeting_room_failover_and_report(): void
    {
        Storage::fake('local');
        [$company, $admin, $token] = $this->makeCompany('Visio SARL');
        $api = fn () => $this->api($token);

        // Sans compte vidéo : seul le pair-à-pair est disponible
        $api()->getJson('/api/visio')->assertOk()->assertJson(['configured' => true, 'cloud' => false]);
        // Les clés de l'infrastructure sont réservées à l'éditeur : un administrateur de société ne peut pas les saisir
        $api()->putJson('/api/visio/settings', ['zego_app_id' => '123456789', 'zego_secret' => self::ZEGO_SECRET])->assertOk()->assertJson(['can_configure_providers' => false]);
        $api()->getJson('/api/visio')->assertJson(['cloud' => false]);
        $admin->forceFill(['is_super_admin' => true])->save();
        $api()->putJson('/api/visio/settings', ['agora_app_id' => 'xyz'])->assertStatus(422);
        $s = $api()->putJson('/api/visio/settings', ['zego_app_id' => '123456789', 'zego_secret' => self::ZEGO_SECRET, 'agora_app_id' => self::AGORA_ID,
            'agora_certificate' => self::AGORA_CERT, 'priority' => ['zego', 'agora', 'p2p'], 'agora_budget_minutes' => 1])->assertOk()->json();
        $this->assertEquals(['zego', 'agora', 'p2p'], $s['priority']);
        $this->assertEquals('••••8271', $s['zego_secret_hint']);
        $api()->postJson('/api/visio/settings/test/agora')->assertOk()->assertJson(['ok' => true]);

        $bob = User::create(['company_id' => $company->id, 'name' => 'Bob Kouassi', 'email' => 'bob@visio.ci', 'password' => 'secret123',
            'role_id' => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $res = $api()->postJson('/api/visio/meetings', ['title' => 'Point commercial', 'description' => 'Pipeline du mois', 'starts_at' => now()->addHour()->toDateTimeString(),
            'duration_minutes' => 45, 'user_ids' => [$bob->id], 'guests' => [['name' => 'Awa Client', 'email' => 'awa@client.ci']], 'options' => ['lobby' => true]])
            ->assertCreated()->json();
        $id = $res['data']['id'];
        $code = $res['data']['code'];
        $this->assertMatchesRegularExpression('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/', $code);
        $this->assertEquals(3, $res['data']['invited']);
        $hostToken = Participant::where('meeting_id', $id)->where('user_id', $admin->id)->value('token');
        $this->assertStringContainsString('/visio/'.$code.'?t='.$hostToken, $res['join_url']);
        // Bob voit la réunion, un autre membre non
        $bobApi = fn () => $this->api($bob->createToken('t')->plainTextToken);
        $this->assertCount(1, $bobApi()->getJson('/api/visio')->assertOk()->json('meetings'));
        $this->get('/visio/'.$code)->assertOk()->assertSee('VISIO', false)
            // Lien ouvert dans un navigateur : proposition de l'application, lien du store selon l'appareil
            ->assertSee('id="get-app"', false)->assertSee('apps.microsoft.com', false)->assertSee('deeplinks = false', false);

        // L'organisateur entre : ZEGOCLOUD (priorité 1) est choisi
        $j = $this->room($hostToken)->postJson("/api/visio-room/$code/join", [])->assertOk()->json();
        $this->assertEquals(['admitted', 'zego', 0], [$j['me']['status'], $j['rtc']['provider'], $j['rtc']['epoch']]);
        $this->assertEquals('p'.$j['me']['id'], Tokens::decodeZego($j['rtc']['token'], self::ZEGO_SECRET)['user_id']);
        $this->assertEquals('live', Meeting::withoutGlobalScopes()->find($id)->status);

        // Invité externe sans lien : salle d'attente (option), puis admis par l'organisateur
        $g = $this->room(null)->postJson("/api/visio-room/$code/join", ['name' => 'Yao Visiteur'])->assertOk()->json();
        $this->assertEquals(['lobby', null], [$g['me']['status'], $g['rtc']]);
        $this->room($g['token'])->getJson("/api/visio-room/$code/rtc")->assertStatus(409);
        $poll = $this->room($hostToken)->getJson("/api/visio-room/$code/poll?since=0")->assertOk()->json();
        $this->assertEquals('Yao Visiteur', $poll['lobby'][0]['name']);
        $this->room($g['token'])->postJson("/api/visio-room/$code/control", ['action' => 'admit', 'target' => $g['me']['id']])->assertForbidden();
        $this->room($hostToken)->postJson("/api/visio-room/$code/control", ['action' => 'admit', 'target' => $g['me']['id']])->assertOk();
        $rtc = $this->room($g['token'])->getJson("/api/visio-room/$code/rtc")->assertOk()->json();
        $this->assertEquals('zego', $rtc['provider']);

        // Conversation, réactions, sous-titres, main levée, sondage
        $this->room($g['token'])->postJson("/api/visio-room/$code/chat", ['body' => 'Bonjour à tous'])->assertOk();
        $this->room($hostToken)->postJson("/api/visio-room/$code/chat", ['body' => 'Message privé', 'to' => $g['me']['id']])->assertOk();
        $this->room($g['token'])->postJson("/api/visio-room/$code/react", ['emoji' => '👏'])->assertNoContent();
        $this->room($g['token'])->postJson("/api/visio-room/$code/react", ['emoji' => '💩'])->assertStatus(422);
        $this->room($hostToken)->postJson("/api/visio-room/$code/caption", ['text' => 'Nous avons décidé de lancer la promotion de décembre.'])->assertNoContent();
        $this->room($g['token'])->postJson("/api/visio-room/$code/caption", ['text' => 'Je vais envoyer le devis révisé avant vendredi.'])->assertNoContent();
        $this->room($g['token'])->postJson("/api/visio-room/$code/state", ['hand' => true, 'mic' => true])->assertOk()->assertJson(['hand' => true, 'mic' => true]);
        $pid = $this->room($hostToken)->postJson("/api/visio-room/$code/polls", ['question' => 'On valide ?', 'options' => ['Oui', 'Non']])->assertOk()->json('id');
        $this->room($g['token'])->postJson("/api/visio-room/$code/polls/$pid/vote", ['choices' => [0]])->assertNoContent();
        $p = $this->room($g['token'])->getJson("/api/visio-room/$code/poll?since=0")->assertOk()->json();
        $this->assertEquals([1, 0], $p['polls'][0]['counts']);
        $kinds = collect($p['feed'])->pluck('kind')->unique()->values()->all();
        $this->assertContains('chat', $kinds);
        $this->assertContains('caption', $kinds);
        $this->assertTrue(collect($p['feed'])->contains(fn ($f) => $f['body'] === 'Message privé'));
        $this->assertEquals(2, $p['count']);

        // Bascule : ZEGOCLOUD refuse la connexion → Agora, époque suivante, jeton Agora valide
        $f = $this->room($g['token'])->postJson("/api/visio-room/$code/failure", ['provider' => 'zego', 'epoch' => 0, 'kind' => 'join', 'detail' => 'quota épuisé'])->assertOk()->json();
        $this->assertEquals(['agora', 1], [$f['provider'], $f['epoch']]);
        $this->assertEquals([self::AGORA_ID, 'yv'.$id, (string) $g['me']['id']], [Tokens::decodeAgora($f['rtc']['token'])['app_id'], $f['rtc']['channel'], Tokens::decodeAgora($f['rtc']['token'])['uid']]);
        $this->assertEquals(100000000 + $g['me']['id'], (int) Tokens::decodeAgora($f['rtc']['screen_token'])['uid']);
        // Signalement tardif d'un autre navigateur sur l'ancienne époque : ignoré
        $this->room($hostToken)->postJson("/api/visio-room/$code/failure", ['provider' => 'zego', 'epoch' => 0, 'kind' => 'join'])->assertOk()->assertJson(['epoch' => 1, 'provider' => 'agora']);
        $this->assertEquals(1, $this->room($hostToken)->getJson("/api/visio-room/$code/poll?since=0")->json('epoch'));
        $this->assertNotNull(DB::table('vis_provider_health')->where('company_id', $company->id)->where('provider', 'zego')->value('down_until'));

        // Quota mensuel Agora (1 min) dépassé pendant la réunion : bascule avant la coupure (ZEGO à l'écart → pair-à-pair)
        DB::table('vis_usage')->updateOrInsert(['company_id' => $company->id, 'provider' => 'agora', 'month' => now()->format('Y-m')], ['seconds' => 70]);
        Participant::where('meeting_id', $id)->where('token', $hostToken)->update(['last_seen_at' => now()->subSeconds(5)]);
        $this->room($hostToken)->getJson("/api/visio-room/$code/poll")->assertOk();
        $m = Meeting::withoutGlobalScopes()->find($id);
        $this->assertEquals(['p2p', 2], [$m->provider, $m->epoch]);
        $this->assertNull(DB::table('vis_provider_health')->where('company_id', $company->id)->where('provider', 'agora')->value('down_until'), 'un quota atteint ne met pas le fournisseur en panne');
        $pp = $this->room($g['token'])->getJson("/api/visio-room/$code/rtc")->assertOk()->json();
        $this->assertEquals('p2p', $pp['provider']);
        $this->assertNotEmpty($pp['ice']);
        $this->room($g['token'])->postJson("/api/visio-room/$code/signal", ['to' => $j['me']['id'], 'data' => ['type' => 'offer', 'sdp' => 'v=0', 'epoch' => 2]])->assertNoContent();
        $sig = collect($this->room($hostToken)->getJson("/api/visio-room/$code/poll?since=0")->json('feed'))->firstWhere('kind', 'signal');
        $this->assertEquals('offer', $sig['data']['type']);
        $this->assertFalse(collect($this->room($g['token'])->getJson("/api/visio-room/$code/poll?since=0")->json('feed'))->contains('kind', 'signal'), 'un signal n\'est remis qu\'à son destinataire');
        // Instabilité à deux participants : un signalement suffit ; le fournisseur rétabli reprend la main
        DB::table('vis_provider_health')->where('company_id', $company->id)->update(['down_until' => null]);
        DB::table('vis_usage')->where('company_id', $company->id)->update(['seconds' => 0]);
        $this->room($g['token'])->postJson("/api/visio-room/$code/failure", ['provider' => 'p2p', 'epoch' => 2, 'kind' => 'unstable'])->assertOk()->assertJson(['provider' => 'zego', 'epoch' => 3]);

        // Enregistrement par morceaux
        $rid = $this->room($hostToken)->postJson("/api/visio-room/$code/recordings", ['mime' => 'video/webm'])->assertOk()->json('id');
        $this->room($hostToken)->call('POST', "/api/visio-room/$code/recordings/$rid/chunk?seq=0", [], [], [], ['HTTP_X_VISIO_TOKEN' => $hostToken, 'HTTP_ACCEPT' => 'application/json'], 'AAAA')->assertOk();
        $this->room($hostToken)->call('POST', "/api/visio-room/$code/recordings/$rid/chunk?seq=1", [], [], [], ['HTTP_X_VISIO_TOKEN' => $hostToken, 'HTTP_ACCEPT' => 'application/json'], 'BBB')->assertOk();
        $this->room($hostToken)->call('POST', "/api/visio-room/$code/recordings/$rid/chunk?seq=0", [], [], [], ['HTTP_X_VISIO_TOKEN' => $hostToken, 'HTTP_ACCEPT' => 'application/json'], 'AAAA')->assertStatus(409);
        $this->room($hostToken)->postJson("/api/visio-room/$code/recordings/$rid/stop", ['duration' => 30])->assertNoContent();
        $rec = DB::table('vis_recordings')->find($rid);
        $this->assertEquals([7, 'ready'], [(int) $rec->size, $rec->status]);
        $this->assertSame('AAAABBB', Storage::disk('local')->get($rec->path));

        // Retrait d'un participant, puis fin pour tous → compte rendu (sans IA : extraction), tâches créées
        $this->room($hostToken)->postJson("/api/visio-room/$code/control", ['action' => 'mute', 'target' => $g['me']['id']])->assertOk();
        $this->assertFalse((bool) Participant::find($g['me']['id'])->mic);
        $this->room($hostToken)->postJson("/api/visio-room/$code/control", ['action' => 'end'])->assertOk();
        $this->assertEquals('ended', Meeting::withoutGlobalScopes()->find($id)->status);
        $show = $api()->getJson("/api/visio/meetings/$id")->assertOk()->json();
        $this->assertNotNull($show['report']);
        $this->assertEquals('auto', $show['report']['source']);
        $this->assertStringContainsString('Point commercial', $show['report']['summary']);
        $this->assertStringContainsString('décidé', implode(' ', $show['report']['decisions']));
        $this->assertStringContainsString('devis', $show['report']['actions'][0]['title']);
        $this->assertStringContainsString('Yao Visiteur', $show['report']['transcript']);
        $this->assertEquals(2, $show['report']['stats']['present']);
        $this->assertEquals(1, $show['report']['stats']['messages']);
        $this->assertTrue(DB::table('user_todos')->where('user_id', $admin->id)->where('title', 'like', '%devis%')->exists());
        $this->assertCount(1, $show['recordings']);
        $this->get(parse_url($show['report']['url'], PHP_URL_PATH))->assertOk()->assertSee('Compte rendu')->assertSee('Yao Visiteur');
        $api()->get('/api/visio/recordings/'.$rid)->assertOk();
        $api()->putJson("/api/visio/meetings/$id/report", ['summary' => 'Résumé corrigé', 'actions' => [['title' => 'Relancer Awa', 'owner' => 'Bob Kouassi']]])
            ->assertOk()->assertJson(['report' => ['summary' => 'Résumé corrigé']]);
        // Une salle terminée refuse les invités, l'organisateur peut la rouvrir
        $this->room($g['token'])->postJson("/api/visio-room/$code/join", [])->assertStatus(410);
        $this->room($hostToken)->postJson("/api/visio-room/$code/join", [])->assertOk();
        $this->assertEquals('live', Meeting::withoutGlobalScopes()->find($id)->status);

        // Utilisateur sans droit visio
        $clerk = User::create(['company_id' => $company->id, 'name' => 'Caissier', 'email' => 'c@visio.ci', 'password' => 'secret123',
            'role_id' => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Caissier')->value('id')]);
        $this->api($clerk->createToken('t')->plainTextToken)->getJson('/api/visio')->assertForbidden();
        $bobApi()->getJson('/api/visio/settings')->assertForbidden();

        // Salle abandonnée : clôturée par la tâche planifiée
        Participant::where('meeting_id', $id)->update(['last_seen_at' => now()->subMinutes(15)]);
        $r = app(Housekeeping::class)->run();
        $this->assertEquals(1, $r['closed']);
        $this->assertEquals('ended', Meeting::withoutGlobalScopes()->find($id)->status);
    }

    public function test_webinar_registration_qa_and_roles(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Webi SA');
        $admin->forceFill(['is_super_admin' => true])->save();
        $api = fn () => $this->api($token);
        $api()->putJson('/api/visio/settings', ['agora_app_id' => self::AGORA_ID, 'agora_certificate' => self::AGORA_CERT, 'priority' => 'agora,zego,p2p'])->assertOk();
        $w = $api()->postJson('/api/visio/meetings', ['type' => 'webinar', 'title' => 'Lancement Facture Normalisée', 'starts_at' => now()->addDay()->toDateTimeString(),
            'capacity' => 2, 'create_leads' => true, 'guests' => [['name' => 'Expert Invité', 'email' => 'expert@cabinet.ci', 'organization' => 'Cabinet KPMG']]])->assertCreated()->json('data');
        $this->assertEquals('lancement-facture-normalisee', $w['slug']);
        $this->assertSame('panelist', Participant::where('meeting_id', $w['id'])->where('email', 'expert@cabinet.ci')->value('role'));

        $this->get('/webinaire/'.$w['slug'])->assertOk()->assertSee('Lancement Facture Normalisée')->assertSee('Expert Invité');
        $this->post('/webinaire/'.$w['slug'], ['name' => 'Fatou Diallo', 'email' => 'fatou@pme.ci', 'organization' => 'PME Plus', 'source' => 'facebook'])->assertRedirect();
        $this->post('/webinaire/'.$w['slug'], ['name' => 'Fatou Diallo', 'email' => 'FATOU@pme.ci'])->assertRedirect('/webinaire/'.$w['slug'].'?deja=1'); // déjà inscrite : lien renvoyé par e-mail, jamais affiché
        // l'adresse d'un intervenant ne donne pas son lien (prise de contrôle de la salle)
        $this->post('/webinaire/'.$w['slug'], ['name' => 'Pirate', 'email' => 'expert@cabinet.ci'])->assertRedirect('/webinaire/'.$w['slug'].'?deja=1');
        $this->post('/webinaire/'.$w['slug'], ['name' => 'Koffi', 'email' => 'koffi@pme.ci'])->assertRedirect();
        $this->post('/webinaire/'.$w['slug'], ['name' => 'Trop tard', 'email' => 'late@pme.ci'])->assertSessionHasErrors('email'); // complet
        $fatou = Participant::where('meeting_id', $w['id'])->where('email', 'fatou@pme.ci')->first();
        $this->assertEquals(['attendee', 'facebook'], [$fatou->role, $fatou->source]);
        $this->assertEquals(2, Participant::where('meeting_id', $w['id'])->where('role', 'attendee')->count());
        $this->assertTrue(DB::table('crm_leads')->where('company_id', $company->id)->where('email', 'fatou@pme.ci')->where('source', 'webinaire')->exists());
        $this->get('/webinaire/'.$w['slug'].'?inscrit='.$fatou->token)->assertOk()->assertSee('Inscription confirmée');
        $this->get('/webinaire/'.$w['slug'].'/calendrier.ics?t='.$fatou->token)->assertOk()->assertSee('BEGIN:VEVENT', false);

        // Sans lien personnel : renvoi vers l'inscription
        $code = $w['code'];
        $this->get('/visio/'.$code)->assertRedirect('/webinaire/'.$w['slug']);
        $this->room(null)->postJson("/api/visio-room/$code/join", ['name' => 'Anonyme'])->assertForbidden();

        $host = Participant::where('meeting_id', $w['id'])->where('user_id', $admin->id)->value('token');
        $this->room($host)->postJson("/api/visio-room/$code/join")->assertOk();
        $j = $this->room($fatou->token)->postJson("/api/visio-room/$code/join")->assertOk()->json();
        $this->assertEquals(['admitted', false, 'agora', 'live'], [$j['me']['status'], $j['me']['publish'], $j['rtc']['provider'], $j['rtc']['mode']]);
        // Un spectateur ne publie ni micro ni caméra
        $this->room($fatou->token)->postJson("/api/visio-room/$code/state", ['mic' => true, 'cam' => true])->assertOk()->assertJson(['mic' => false]);
        $this->room($fatou->token)->postJson("/api/visio-room/$code/caption", ['text' => 'test'])->assertForbidden();

        // Questions-réponses votées
        $q = $this->room($fatou->token)->postJson("/api/visio-room/$code/questions", ['body' => 'La facture normalisée est-elle obligatoire pour les TPE ?'])->assertOk()->json('id');
        $this->room($host)->postJson("/api/visio-room/$code/questions/$q/vote")->assertNoContent();
        $this->room($fatou->token)->postJson("/api/visio-room/$code/questions/$q/vote")->assertNoContent();
        $this->room($fatou->token)->postJson("/api/visio-room/$code/questions/$q/answer", ['answer' => 'x'])->assertForbidden();
        $this->room($host)->postJson("/api/visio-room/$code/questions/$q/answer", ['answer' => 'Oui, dès janvier.'])->assertNoContent();
        $qs = $this->room($fatou->token)->getJson("/api/visio-room/$code/poll?since=0")->assertOk()->json('questions');
        $this->assertEquals([2, true, 'answered', 'Oui, dès janvier.'], [$qs[0]['votes'], $qs[0]['voted'], $qs[0]['status'], $qs[0]['answer']]);

        // Main levée puis « donner la parole » : le spectateur devient intervenant
        $this->room($fatou->token)->postJson("/api/visio-room/$code/state", ['hand' => true])->assertOk();
        $this->room($host)->postJson("/api/visio-room/$code/control", ['action' => 'promote', 'target' => $fatou->id])->assertOk();
        $me = $this->room($fatou->token)->getJson("/api/visio-room/$code/poll")->assertOk()->json('me');
        $this->assertEquals(['panelist', true, false], [$me['role'], $me['publish'], $me['hand']]);

        // Fin, statistiques, export CSV, rediffusion indisponible sans enregistrement
        $this->room($host)->postJson("/api/visio-room/$code/control", ['action' => 'end'])->assertOk();
        $show = $api()->getJson('/api/visio/meetings/'.$w['id'])->assertOk()->json();
        $this->assertEquals(1, $show['report']['stats']['questions']);
        $csv = $api()->get('/api/visio/meetings/'.$w['id'].'/participants.csv')->assertOk()->getContent();
        $this->assertStringContainsString('"Fatou Diallo";fatou@pme.ci', $csv);
        $this->get('/webinaire/'.$w['slug'].'/replay')->assertNotFound();
        $this->get('/webinaire/'.$w['slug'])->assertOk()->assertSee('Webinaire terminé');

        // Rappels : la veille
        $w2 = $api()->postJson('/api/visio/meetings', ['type' => 'webinar', 'title' => 'Atelier Paie', 'starts_at' => now()->addHours(24)->toDateTimeString()])->assertCreated()->json('data');
        $this->post('/webinaire/'.$w2['slug'], ['name' => 'Ali', 'email' => 'ali@pme.ci'])->assertRedirect();
        app(Housekeeping::class)->run();
        $this->assertArrayHasKey('24h', Meeting::withoutGlobalScopes()->find($w2['id'])->reminders_sent ?? []);
        // Annulation d'un webinaire futur
        $api()->deleteJson('/api/visio/meetings/'.$w2['id'])->assertNoContent();
        $this->get('/webinaire/'.$w2['slug'])->assertNotFound();
    }
}
