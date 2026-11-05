<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\Module;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    protected string $token;

    protected ArrayTransport $mails;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->token] = $this->makeCompany('Boutique Mode');
        $this->mails = new ArrayTransport;
        Smtp::$transportFactory = fn () => $this->mails;
        $this->api($this->token)->getJson('/api/bootstrap')->assertOk();
    }

    protected function tearDown(): void
    {
        Smtp::$transportFactory = null;
        parent::tearDown();
    }

    protected function smtp(): void
    {
        $this->api($this->token)->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.boutique.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'news@boutique.ci', 'password' => 'S3cret!pass', 'from_email' => 'news@boutique.ci', 'from_name' => 'Boutique Mode',
        ]])->assertOk();
    }

    protected function sentHtml(int $i = 0): string
    {
        return $this->mails->messages()->values()[$i]->getOriginalMessage()->getHtmlBody();
    }

    public function test_channels_are_configured_tested_and_secrets_masked(): void
    {
        $this->smtp();
        $setup = $this->api($this->token)->getJson('/api/messaging/setup')->json();
        $this->assertSame('smtp', $setup['available']['email']);
        $this->assertStringNotContainsString('S3cret', json_encode($setup));
        // Ressaisie sans mot de passe : conservé
        $this->api($this->token)->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => ['from_name' => 'Boutique Mode Abidjan', 'password' => '']])->assertOk();
        $this->assertSame('S3cret!pass', \App\Models\MessagingChannel::first()->setting('password'));

        $this->api($this->token)->postJson('/api/messaging/test', ['channel' => 'email', 'to' => 'moi@boutique.ci'])->assertOk();
        $this->assertCount(1, $this->mails->messages());
        $this->assertSame('Boutique Mode Abidjan', $this->mails->messages()->first()->getOriginalMessage()->getFrom()[0]->getName());

        // SMS Infobip et WhatsApp (API Cloud de Meta)
        Http::fake([
            'abc12.api.infobip.com/*' => Http::response(['messages' => [['messageId' => 'ib-1', 'status' => ['groupName' => 'PENDING']]]]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]]),
        ]);
        $this->api($this->token)->putJson('/api/messaging/channels/sms', ['provider' => 'infobip', 'settings' => ['base_url' => 'abc12.api.infobip.com', 'api_key' => 'ib-key', 'sender' => 'BOUTIQUE']])->assertOk();
        $this->api($this->token)->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '1098', 'access_token' => 'EAAG-token']])->assertOk();
        $this->api($this->token)->postJson('/api/messaging/test', ['channel' => 'sms', 'to' => '07 07 07 07 07'])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'infobip') && $r['messages'][0]['destinations'][0]['to'] === '2250707070707' && $r->hasHeader('Authorization', 'App ib-key'));

        // Envoi d'une facture PDF par WhatsApp : lien public non devinable vers le document
        $pdf = UploadedFile::fake()->createWithContent('Facture FAC-2026-00012.pdf', '%PDF-1.4 test');
        $this->api($this->token)->post('/api/messaging/send', ['channel' => 'whatsapp', 'to' => '+225 05 55 55 55 55', 'message' => 'Bonjour, voici votre facture.', 'attachment' => $pdf, 'context' => 'document:x'])->assertOk();
        Http::assertSent(function ($r) {
            if (! str_contains($r->url(), 'graph.facebook.com/v21.0/1098/messages')) {
                return false;
            }
            $this->assertSame('document', $r['type']);
            $this->assertMatchesRegularExpression('#/uploads/shared/\d+/[A-Za-z0-9]{40}\.pdf$#', $r['document']['link']);

            return $r['to'] === '2250555555555';
        });
        // … et par e-mail en pièce jointe
        $this->api($this->token)->post('/api/messaging/send', ['channel' => 'email', 'to' => 'client@hotel.ci', 'subject' => 'Votre facture', 'message' => 'Ci-joint.', 'attachment' => UploadedFile::fake()->createWithContent('facture.pdf', '%PDF')])->assertOk();
        $this->assertSame('facture.pdf', $this->mails->messages()->last()->getOriginalMessage()->getAttachments()[0]->getFilename());
        $this->assertSame(4, \App\Models\MessagingLog::where('status', 'sent')->count());

        // Module désactivé : le canal est verrouillé
        Module::where('key', 'sms')->update(['is_active' => false]);
        app(\App\Support\ModuleRegistry::class)->flush();
        $this->api($this->token)->postJson('/api/messaging/test', ['channel' => 'sms', 'to' => '0707070707'])->assertStatus(422);
        $this->api($this->token)->putJson('/api/messaging/channels/sms', ['provider' => 'twilio', 'settings' => []])->assertStatus(422);
    }

    public function test_email_campaign_personalised_tracked_and_unsubscribe(): void
    {
        $this->smtp();
        $ov = $this->api($this->token)->getJson('/api/marketing/overview')->assertOk()->json();
        $list = $ov['lists'][0]['id'];
        $this->assertGreaterThanOrEqual(5, count($ov['templates']));
        $this->assertSame('sms', collect($ov['templates'])->firstWhere('name', 'SMS promotion')['channel']);
        $this->assertSame('whatsapp', collect($ov['templates'])->firstWhere('name', 'WhatsApp nouveautés')['channel']);
        $newsletter = collect($ov['templates'])->firstWhere('name', 'Proposition commerciale')['id'];

        // Contacts : clients de l'ERP + texte collé depuis Excel (doublon fusionné)
        $this->api($this->token)->postJson('/api/core/partners', ['name' => 'Hôtel Ivoire', 'email' => 'achats@hotel.ci', 'phone' => '0102030405'])->assertCreated();
        $this->api($this->token)->postJson('/api/core/partners', ['name' => 'Sans contact'])->assertCreated();
        $r = $this->api($this->token)->postJson('/api/marketing/import', ['list_id' => $list, 'source' => 'partners'])->assertOk()->json();
        $this->assertSame(1, $r['added']);
        $this->assertSame(1, $r['skipped']);
        $r = $this->api($this->token)->postJson('/api/marketing/import', ['list_id' => $list, 'source' => 'text', 'text' => "Prénom;Nom;E-mail;Téléphone\nAwa;Koné;awa@mail.ci;0707070707\nYao;Kouassi;ACHATS@hotel.ci;\nMoussa;;;0505050505"])->assertOk()->json();
        $this->assertSame(['added' => 2, 'updated' => 1, 'skipped' => 0, 'list_total' => 3], $r);

        $c = $this->api($this->token)->postJson('/api/marketing/campaigns', ['name' => 'Offre séminaires', 'kind' => 'proposal', 'template_id' => $newsletter, 'list_ids' => [$list]])->assertCreated()->json();
        $this->assertSame('Notre proposition pour {{societe}}', $c['subject']);
        // Bloc HTML libre conçu par le client (les scripts sont retirés)
        $blocks = array_merge($c['blocks'], [['type' => 'html', 'html' => '<div style="color:red" onclick="x()">Tarifs <b>spéciaux</b><script>alert(1)</script></div>'], ['type' => 'button', 'label' => 'Voir l\'offre', 'url' => 'https://boutique.ci/offre']]);
        $this->api($this->token)->putJson('/api/marketing/campaigns/'.$c['id'], ['blocks' => $blocks])->assertOk()->assertJsonPath('recipients_estimate', 2); // Moussa n'a pas d'e-mail
        $this->api($this->token)->post('/api/marketing/campaigns/'.$c['id'].'/attachments', ['file' => UploadedFile::fake()->createWithContent('Proposition.pdf', '%PDF-1.4')])->assertOk();

        $this->api($this->token)->postJson('/api/marketing/campaigns/'.$c['id'].'/test', ['to' => 'moi@boutique.ci'])->assertOk();
        $this->assertStringStartsWith('[TEST]', $this->mails->messages()->first()->getOriginalMessage()->getSubject());

        $sent = $this->api($this->token)->postJson('/api/marketing/campaigns/'.$c['id'].'/send')->assertOk()->json();
        $this->assertSame('sent', $sent['status']);
        $this->assertSame(2, $sent['stats']['sent']);
        $this->assertCount(3, $this->mails->messages()); // test + 2

        $awa = collect($this->mails->messages()->values())->first(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'awa@mail.ci')->getOriginalMessage();
        $html = $awa->getHtmlBody();
        $this->assertStringContainsString('Bonjour Awa,', $html);
        $this->assertStringContainsString('Tarifs <b>spéciaux</b>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertSame('Proposition.pdf', $awa->getAttachments()[0]->getFilename());
        $this->assertNotNull($awa->getHeaders()->get('List-Unsubscribe'));

        // Suivi : clic sur le bouton (lien redirigé), pixel d'ouverture, désinscription
        preg_match('#href="([^"]+/m/c/[^"]+)"#', $html, $click);
        $this->assertNotEmpty($click);
        $this->get(html_entity_decode($click[1]))->assertRedirect('https://boutique.ci/offre');
        preg_match('#src="[^"]+(/m/o/[A-Za-z0-9]+\.gif)"#', $html, $pixel);
        $this->get($pixel[1])->assertOk()->assertHeader('Content-Type', 'image/gif');
        preg_match('#(/m/u/[A-Za-z0-9]+)#', $html, $unsub);
        $this->get($unsub[1])->assertOk()->assertSee('Me désinscrire');
        $this->post($unsub[1])->assertOk()->assertSee('vous ne recevrez plus nos e-mails', false);

        $stats = $this->api($this->token)->getJson('/api/marketing/campaigns/'.$c['id'])->json('stats');
        $this->assertSame(1, $stats['opened']);
        $this->assertSame(1, $stats['clicked']);
        $this->assertSame(1, $stats['unsubscribed']);
        $this->assertEquals(50, $stats['open_rate']);

        // Une nouvelle campagne n'est plus envoyée à la personne désinscrite
        $copy = $this->api($this->token)->postJson('/api/marketing/campaigns/'.$c['id'].'/duplicate')->assertCreated()->json('id');
        $this->assertSame(1, $this->api($this->token)->getJson("/api/marketing/campaigns/$copy")->json('recipients_estimate'));
        $this->api($this->token)->putJson('/api/marketing/campaigns/'.$c['id'], ['subject' => 'x'])->assertStatus(422); // envoyée : figée

        // Aperçu dans le navigateur
        $url = $this->api($this->token)->postJson('/api/marketing/preview', ['blocks' => [['type' => 'heading', 'text' => 'Bonjour {{prenom}}']]])->assertOk()->json('url');
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertSee('Bonjour Awa');
    }

    public function test_sms_campaign_scheduled_and_platform_mail_server_with_quota(): void
    {
        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        $this->api($this->token)->putJson('/api/messaging/channels/sms', ['provider' => 'twilio', 'settings' => ['account_sid' => 'AC1', 'auth_token' => 't', 'from' => 'BOUTIQUE']])->assertOk();
        $list = $this->api($this->token)->postJson('/api/marketing/lists', ['name' => 'VIP'])->json('id');
        foreach (['0707070707', '+225 0505050505', '07 07 07 07 07'] as $i => $p) {
            $this->api($this->token)->postJson('/api/marketing/contacts', ['first_name' => "Client$i", 'phone' => $p, 'list_ids' => [$list]])->assertSuccessful();
        }
        $this->assertSame(2, \App\Modules\Marketing\Models\Contact::count()); // doublon de numéro fusionné

        $c = $this->api($this->token)->postJson('/api/marketing/campaigns', ['name' => 'Soldes', 'channel' => 'sms', 'text' => '{{prenom}}, -30% ce week-end chez {{entreprise}} !', 'list_ids' => [$list]])->json('id');
        $this->api($this->token)->postJson("/api/marketing/campaigns/$c/send", ['scheduled_at' => now()->addHour()->toIso8601String()])->assertOk()->assertJsonPath('status', 'scheduled');
        Artisan::call('erp:marketing-dispatch');
        Http::assertNothingSent();
        $this->travel(2)->hours();
        Artisan::call('erp:marketing-dispatch');
        $this->assertSame('sent', $this->api($this->token)->getJson("/api/marketing/campaigns/$c")->json('status'));
        Http::assertSentCount(2);
        Http::assertSent(fn ($r) => str_contains($r['Body'], 'Client0, -30% ce week-end chez Boutique Mode') && str_contains($r['Body'], 'STOP'));

        // Serveur de messagerie de l'ERP (module Messagerie hébergée) avec quota
        [, , $admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        $this->api($admin)->putJson('/api/admin/mail-platform', ['host' => 'smtp.yomara.ci', 'port' => 587, 'encryption' => 'tls', 'username' => 'erp', 'password' => 'pw', 'from_email' => 'noreply@yomara.ci', 'from_name' => 'Yomara ERP', 'quota' => 1])->assertOk()->assertJsonPath('settings.has_password', true);
        $this->api($this->token)->putJson('/api/messaging/channels/email', ['provider' => 'platform', 'settings' => ['from_name' => 'Boutique Mode', 'reply_to' => 'contact@boutique.ci']])->assertOk();
        $this->api($this->token)->postJson('/api/messaging/test', ['channel' => 'email', 'to' => 'a@b.ci'])->assertOk();
        $sent = $this->mails->messages()->first()->getOriginalMessage();
        $this->assertSame('noreply@yomara.ci', $sent->getFrom()[0]->getAddress());
        $this->assertSame('Boutique Mode', $sent->getFrom()[0]->getName());
        $this->assertSame('contact@boutique.ci', $sent->getReplyTo()[0]->getAddress());
        $this->api($this->token)->postJson('/api/messaging/test', ['channel' => 'email', 'to' => 'a@b.ci'])->assertStatus(422)->assertJsonFragment(['message' => 'Quota mensuel d\'e-mails du serveur de l\'ERP atteint.']);
    }
}
