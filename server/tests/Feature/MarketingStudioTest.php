<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\Company;
use App\Modules\Marketing\AutomationEngine;
use App\Modules\Marketing\Models\AutomationRun;
use App\Modules\Marketing\Models\CampaignMessage;
use App\Modules\Marketing\Models\Contact;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketingStudioTest extends TestCase
{
    protected string $token;

    protected Company $company;

    protected ArrayTransport $mails;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->company, , $this->token] = $this->makeCompany('Boutique Mode');
        $this->mails = new ArrayTransport;
        Smtp::$transportFactory = fn () => $this->mails;
        $this->api($this->token)->getJson('/api/bootstrap')->assertOk();
        $this->api($this->token)->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.boutique.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'news@boutique.ci', 'password' => 'x', 'from_email' => 'news@boutique.ci', 'from_name' => 'Boutique Mode',
        ]])->assertOk();
    }

    protected function tearDown(): void
    {
        Smtp::$transportFactory = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function mailsTo(string $email): array
    {
        return collect($this->mails->messages()->values())->map->getOriginalMessage()->filter(fn ($m) => $m->getTo()[0]->getAddress() === $email)->values()->all();
    }

    protected function tick(): void
    {
        app()->instance('tenant', $this->company);
        app(AutomationEngine::class)->tick($this->company);
        app()->forgetInstance('tenant');
    }

    public function test_layouts_and_new_blocks_render(): void
    {
        Http::fake(['blog.boutique.ci/*' => Http::response('<?xml version="1.0"?><rss><channel><item><title>Nouvel arrivage</title><link>https://blog.boutique.ci/arrivage</link><description>&lt;img src="https://blog.boutique.ci/a.jpg"&gt; Des pagnes wax en quantité.</description></item><item><title>Conseils</title><link>https://blog.boutique.ci/conseils</link><description>Bien choisir.</description></item></channel></rss>')]);
        $layouts = $this->api($this->token)->getJson('/api/marketing/layouts')->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(8, count($layouts));
        $this->assertStringContainsString('/marketing/layouts/news.jpg', json_encode($layouts, JSON_UNESCAPED_SLASHES));

        $id = $this->api($this->token)->postJson('/api/marketing/campaigns', ['name' => 'Promo', 'layout' => 'promo'])->assertCreated()->json('id');
        $c = $this->api($this->token)->getJson("/api/marketing/campaigns/$id")->json();
        $this->assertSame('hero', $c['blocks'][0]['type']);
        $blocks = array_merge($c['blocks'], [
            ['type' => 'video', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            ['type' => 'menu', 'links' => "Accueil|https://boutique.ci\nContact|https://boutique.ci/contact"],
            ['type' => 'rss', 'url' => 'https://blog.boutique.ci/feed', 'count' => 2],
            ['type' => 'footer', 'text' => 'Boutique Mode, Cocody'],
            ['type' => 'html', 'html' => '<p onclick="x()">Libre</p><script>alert(1)</script>'],
        ]);
        $html = $this->api($this->token)->postJson('/api/marketing/preview', ['blocks' => $blocks, 'style' => $c['style'], 'subject' => 'X'])->assertOk()->json('html');
        $this->assertStringContainsString('WEEKEND30', $html);
        $this->assertStringContainsString('24 500 F', $html);
        $this->assertStringContainsString('img.youtube.com/vi/dQw4w9WgXcQ', $html);
        $this->assertStringContainsString('Nouvel arrivage', $html);
        $this->assertStringContainsString('https://blog.boutique.ci/a.jpg', $html);
        $this->assertStringContainsString('Libre', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertCount(2, $this->api($this->token)->getJson('/api/marketing/rss?url=https://blog.boutique.ci/feed&count=2')->json('data'));

        // Modèle et page créés depuis une mise en page
        $t = $this->api($this->token)->postJson('/api/marketing/templates', ['name' => 'Bienvenue', 'layout' => 'welcome'])->assertCreated()->json();
        $this->assertSame('BIENVENUE10', collect($t['blocks'])->firstWhere('type', 'coupon')['code']);
    }

    public function test_segments_and_ab_test(): void
    {
        $api = $this->api($this->token);
        $list = $api->postJson('/api/marketing/lists', ['name' => 'Clients'])->json('id');
        for ($i = 1; $i <= 20; $i++) {
            $api->postJson('/api/marketing/contacts', ['first_name' => "C$i", 'email' => "c$i@mail.ci", 'list_ids' => [$list], 'tags' => $i <= 5 ? 'vip, abidjan' : 'abidjan', 'city' => $i % 2 ? 'Abidjan' : 'Bouaké'])->assertCreated();
        }
        $count = $api->postJson('/api/marketing/segments/count', ['match' => 'all', 'conditions' => [['field' => 'tag', 'value' => 'VIP'], ['field' => 'city', 'value' => 'Abidjan']]])->json('count');
        $this->assertSame(3, $count); // 1, 3, 5
        $seg = $api->postJson('/api/marketing/segments', ['name' => 'VIP', 'conditions' => [['field' => 'tag', 'value' => 'vip']]])->assertCreated()->json('id');
        $this->assertSame(5, $api->getJson("/api/marketing/contacts?segment_id=$seg")->json('total'));

        // Campagne vers un segment seul
        $vip = $api->postJson('/api/marketing/campaigns', ['name' => 'VIP', 'channel' => 'email', 'subject' => 'Rien que pour vous', 'blocks' => [['type' => 'text', 'text' => 'Hello']]])->json('id');
        $this->assertSame(5, $api->putJson("/api/marketing/campaigns/$vip", ['segment_ids' => [$seg]])->json('recipients_estimate'));

        // Test A/B : 20 % par variante, le reste attend
        $id = $api->postJson('/api/marketing/campaigns', ['name' => 'AB', 'channel' => 'email', 'subject' => 'Objet A', 'blocks' => [['type' => 'button', 'label' => 'Go', 'url' => 'https://boutique.ci']]])->json('id');
        $api->putJson("/api/marketing/campaigns/$id", ['list_ids' => [$list], 'ab' => ['enabled' => true, 'subject_b' => 'Objet B {{prenom}}', 'percent' => 20, 'wait_hours' => 2, 'metric' => 'open']])->assertOk();
        $c = $api->postJson("/api/marketing/campaigns/$id/send")->assertOk()->json();
        $this->assertSame('sending', $c['status']);
        $this->assertSame(12, $c['stats']['held']);
        $this->assertCount(8, $this->mails->messages());
        $subjects = collect($this->mails->messages()->values())->map(fn ($m) => $m->getOriginalMessage()->getSubject());
        $this->assertSame(4, $subjects->filter(fn ($s) => $s === 'Objet A')->count());
        $this->assertSame(4, $subjects->filter(fn ($s) => str_starts_with($s, 'Objet B C'))->count());

        // Les B sont plus ouverts
        foreach (CampaignMessage::withoutGlobalScopes()->where('campaign_id', $id)->where('variant', 'B')->limit(3)->get() as $m) {
            $this->get('/m/o/'.$m->token.'.gif')->assertOk();
        }
        Carbon::setTestNow(now()->addHours(3));
        Artisan::call('erp:marketing-dispatch');
        $c = $this->api($this->token)->getJson("/api/marketing/campaigns/$id")->json();
        $this->assertSame('B', $c['ab']['winner']);
        $this->assertSame('sent', $c['status']);
        $this->assertCount(20, $this->mails->messages());
        $this->assertSame(16, collect($this->mails->messages()->values())->filter(fn ($m) => str_starts_with($m->getOriginalMessage()->getSubject(), 'Objet B'))->count());
        $this->assertSame(3, $c['variants']['B']['opened']);

        // Carte des clics et score d'engagement
        $m = CampaignMessage::withoutGlobalScopes()->where('campaign_id', $id)->where('variant', 'B')->first();
        $u = rtrim(strtr(base64_encode('https://boutique.ci'), '+/', '-_'), '=');
        $sig = \App\Modules\Marketing\EmailRenderer::linkSig($m->token, 'https://boutique.ci');
        $this->get("/m/c/{$m->token}?u=$u")->assertNotFound(); // lien non signé refusé
        $this->get("/m/c/{$m->token}?u=$u&s=$sig")->assertRedirect('https://boutique.ci');
        $this->get("/m/c/{$m->token}?u=$u&s=$sig")->assertRedirect();
        $links = $this->api($this->token)->getJson("/api/marketing/campaigns/$id")->json('links');
        $this->assertSame(['url' => 'https://boutique.ci', 'clicks' => 2, 'people' => 1], $links[0]);
        $contact = Contact::withoutGlobalScopes()->find($m->contact_id);
        $this->assertSame(4, $contact->score); // ouverture (+1) puis premier clic (+3)
        $detail = $this->api($this->token)->getJson('/api/marketing/contacts/'.$contact->id)->assertOk()->json();
        $this->assertSame('AB', $detail['messages'][0]['source']);
        $this->assertSame(1, $this->api($this->token)->postJson('/api/marketing/segments/count', ['conditions' => [['field' => 'score_min', 'value' => 3]]])->json('count'));
    }

    public function test_signup_form_double_optin_and_welcome_automation(): void
    {
        $api = $this->api($this->token);
        // Scénario de bienvenue tiré des recettes
        $a = $api->postJson('/api/marketing/automations', ['name' => 'Bienvenue', 'trigger' => 'form', 'recipe' => 'welcome'])->assertCreated()->json();
        $this->assertSame(['email', 'wait', 'condition'], array_column($a['steps'], 'type'));
        $this->assertNotEmpty($a['steps'][0]['config']['template_id']);
        $api->postJson("/api/marketing/automations/{$a['id']}/status", ['status' => 'active'])->assertOk();

        $form = $api->postJson('/api/marketing/forms', ['kind' => 'page', 'name' => 'Inscription newsletter', 'title' => 'Restez informé', 'layout' => 'welcome',
            'fields' => [['key' => 'first_name', 'required' => true], ['key' => 'email', 'required' => true], ['key' => 'birthday']]])->assertCreated()->json();
        $this->assertStringEndsWith('/f/inscription-newsletter', $form['url']);

        // Page publique (navigateur, sans en-têtes d'API)
        $this->flushHeaders();
        $this->get('/f/inscription-newsletter')->assertOk()->assertSee('Restez informé')->assertSee('BIENVENUE10')->assertSee('name="email"', false);
        // Champs obligatoires et consentement
        $this->post('/f/inscription-newsletter', ['first_name' => 'Awa'])->assertOk()->assertSee('obligatoire');
        // Piège à robots
        $this->post('/f/inscription-newsletter', ['first_name' => 'Bot', 'email' => 'bot@spam.ci', 'consent' => 1, 'website' => 'http://spam'])->assertOk();
        $this->assertSame(0, Contact::withoutGlobalScopes()->where('email', 'bot@spam.ci')->count());

        $this->post('/f/inscription-newsletter', ['first_name' => 'Awa', 'email' => 'Awa@Mail.ci', 'birthday' => '1994-05-12', 'consent' => 1])->assertOk()->assertSee('Presque terminé');
        $awa = Contact::withoutGlobalScopes()->where('email', 'awa@mail.ci')->first();
        $this->assertSame('pending', $awa->opt_in);
        $this->assertSame('1994-05-12', $awa->birthday->format('Y-m-d'));
        $confirm = $this->mailsTo('awa@mail.ci');
        $this->assertCount(1, $confirm);
        $this->assertStringContainsString('/f/confirm/'.$awa->token, $confirm[0]->getHtmlBody());
        $this->assertSame(0, AutomationRun::withoutGlobalScopes()->count()); // pas encore confirmé

        // Une campagne n'atteint pas un contact non confirmé
        $api = $this->api($this->token);
        $list = $form['list_id'];
        $camp = $api->postJson('/api/marketing/campaigns', ['name' => 'X', 'channel' => 'email', 'subject' => 'X', 'blocks' => [['type' => 'text', 'text' => 'x']]])->json('id');
        $this->assertSame(0, $api->putJson("/api/marketing/campaigns/$camp", ['list_ids' => [$list]])->json('recipients_estimate'));

        // Confirmation → scénario lancé, e-mail de bienvenue envoyé
        $this->flushHeaders();
        $this->get('/f/confirm/'.$awa->token.'?form='.$form['id'])->assertOk()->assertSee('Merci');
        $this->assertSame('confirmed', $awa->fresh()->opt_in);
        $this->tick();
        $welcome = $this->mailsTo('awa@mail.ci');
        $this->assertCount(2, $welcome);
        $this->assertSame('Bienvenue Awa !', $welcome[1]->getSubject());
        $run = AutomationRun::withoutGlobalScopes()->first();
        $this->assertSame('2', $run->cursor); // en attente (3 jours) avant la condition
        $this->assertTrue($run->next_run_at->gt(now()->addDays(2)));

        // Rien avant l'échéance, puis branche « non ouvert » → relance
        $this->tick();
        $this->assertCount(2, $this->mailsTo('awa@mail.ci'));
        Carbon::setTestNow(now()->addDays(3)->addMinute());
        Artisan::call('erp:marketing-dispatch');
        $mails = $this->mailsTo('awa@mail.ci');
        $this->assertCount(3, $mails);
        $this->assertSame('Awa, votre cadeau de bienvenue vous attend', $mails[2]->getSubject());
        $run->refresh();
        $this->assertSame('done', $run->status);
        $this->assertSame('non', $run->history[2]['result']);

        $stats = $this->api($this->token)->getJson("/api/marketing/automations/{$a['id']}")->assertOk()->json();
        $this->assertSame(2, $stats['stats']['sent']);
        $this->assertSame(1, $stats['stats']['done']);
        $this->assertSame(1, \App\Modules\Marketing\Models\Form::withoutGlobalScopes()->find($form['id'])->submissions);
    }

    public function test_tag_trigger_branches_crm_and_birthday(): void
    {
        $api = $this->api($this->token);
        $list = $api->postJson('/api/marketing/lists', ['name' => 'Prospects'])->json('id');
        $vip = $api->postJson('/api/marketing/lists', ['name' => 'VIP'])->json('id');
        $tpl = $api->postJson('/api/marketing/templates', ['name' => 'Offre', 'layout' => 'proposal'])->json('id');
        $a = $api->postJson('/api/marketing/automations', ['name' => 'Chaud', 'trigger' => 'tag', 'trigger_config' => ['tag' => 'Chaud'], 'steps' => [
            ['type' => 'email', 'config' => ['template_id' => $tpl]],
            ['type' => 'condition', 'config' => ['check' => 'has_phone'], 'yes' => [
                ['type' => 'add_list', 'config' => ['list_id' => $vip]],
                ['type' => 'crm_lead', 'config' => ['title' => 'Lead {{nom_complet}}', 'amount' => 250000]],
            ], 'no' => [['type' => 'exit']]],
            ['type' => 'notify', 'config' => ['message' => '{{prenom}} est chaud']],
        ]])->assertCreated()->json();
        // Incomplet : refusé à l'activation
        $bad = $api->postJson('/api/marketing/automations', ['name' => 'Vide', 'trigger' => 'list_join', 'steps' => [['type' => 'sms', 'config' => []]]])->json('id');
        $api->postJson("/api/marketing/automations/$bad/status", ['status' => 'active'])->assertStatus(422);
        $api->postJson("/api/marketing/automations/{$a['id']}/status", ['status' => 'active'])->assertOk();

        $ko = $api->postJson('/api/marketing/contacts', ['first_name' => 'Koffi', 'last_name' => 'K', 'email' => 'koffi@mail.ci', 'phone' => '0707070707', 'list_ids' => [$list]])->json();
        $sans = $api->postJson('/api/marketing/contacts', ['first_name' => 'Sans', 'email' => 'sans@mail.ci', 'list_ids' => [$list]])->json();
        $api->putJson("/api/marketing/contacts/{$ko['id']}", ['tags' => 'chaud', 'list_ids' => [$list]])->assertOk();
        $api->putJson("/api/marketing/contacts/{$sans['id']}", ['tags' => 'Chaud', 'list_ids' => [$list]])->assertOk();
        $this->tick();

        $this->assertCount(1, $this->mailsTo('koffi@mail.ci'));
        $this->assertCount(1, $this->mailsTo('sans@mail.ci'));
        $koffi = Contact::withoutGlobalScopes()->find($ko['id']);
        $this->assertTrue($koffi->lists()->where('mk_lists.id', $vip)->exists());
        $lead = \App\Modules\Crm\Models\Lead::withoutGlobalScopes()->find($koffi->lead_id);
        $this->assertSame('Lead Koffi K', $lead->title);
        $this->assertEquals(250000, $lead->expected_revenue);
        $this->assertSame('campaign', $lead->source);
        $this->assertSame('exited', AutomationRun::withoutGlobalScopes()->where('contact_id', $sans['id'])->first()->status);
        $this->assertSame(1, \App\Models\AppNotification::withoutGlobalScopes()->where('type', 'automation')->count()); // seul Koffi va jusqu'au bout
        // Pas de seconde entrée
        $api->putJson("/api/marketing/contacts/{$ko['id']}", ['tags' => 'chaud, autre', 'list_ids' => [$list]])->assertOk();
        $this->assertSame(1, AutomationRun::withoutGlobalScopes()->where('contact_id', $ko['id'])->count());

        // Anniversaire : lancé une fois par an
        $b = $api->postJson('/api/marketing/automations', ['name' => 'Anniv', 'trigger' => 'birthday', 'recipe' => 'birthday'])->json('id');
        $api->postJson("/api/marketing/automations/$b/status", ['status' => 'active'])->assertOk();
        $api->putJson("/api/marketing/contacts/{$ko['id']}", ['birthday' => now()->subYears(30)->format('Y-m-d'), 'list_ids' => [$list]])->assertOk();
        Http::fake();
        Artisan::call('erp:marketing-birthdays');
        Artisan::call('erp:marketing-birthdays');
        $this->assertSame(1, AutomationRun::withoutGlobalScopes()->where('automation_id', $b)->count());
        $this->tick();
        $this->assertStringStartsWith('Joyeux anniversaire Koffi', $this->mailsTo('koffi@mail.ci')[1]->getSubject());
    }
}
