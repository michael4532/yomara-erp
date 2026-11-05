<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Modules\Portail\Models\PortalAccess;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PortailTest extends TestCase
{
    public function test_customer_portal_invite_documents_quote_acceptance_messages_and_revoke(): void
    {
        [$company, , $token] = $this->makeCompany('Quincaillerie du Port');
        $api = fn () => $this->api($token);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $api()->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '77', 'access_token' => 'tok']])->assertOk();

        $client = $api()->postJson('/api/core/partners', ['name' => 'BTP Kouassi & Fils', 'type' => 'customer', 'phone' => '07 08 09 10 11', 'city' => 'Abidjan'])->json('data.id');
        $other = $api()->postJson('/api/core/partners', ['name' => 'Autre client', 'type' => 'customer'])->json('data.id');
        $doc = function (string $type, string $partner, float $price) use ($api) {
            $d = $api()->postJson('/api/ventes/documents', ['type' => $type, 'partner_id' => $partner, 'date' => now()->toDateString(),
                'lines' => [['description' => 'Ciment CPJ 45', 'quantity' => 10, 'unit_price' => $price, 'tax_rate' => 18]]])->json('data');
            $api()->postJson("/api/ventes/documents/{$d['id']}/validate")->assertOk();

            return SalesDocument::withoutGlobalScopes()->find($d['id']);
        };
        $inv = $doc('invoice', $client, 5000);
        $quote = $doc('quote', $client, 4800);
        $foreign = $doc('invoice', $other, 1000);
        $draft = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->toDateString(),
            'lines' => [['description' => 'Brouillon', 'quantity' => 1, 'unit_price' => 1]]])->json('data.id');

        // Invitation par WhatsApp
        $r = $api()->postJson("/api/portail/partners/$client/invite", ['channel' => 'whatsapp'])->assertOk()->json();
        $this->assertSame('whatsapp', $r['sent_via']);
        $url = $r['url'];
        Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'espace client') && str_contains(json_encode($req->data(), JSON_UNESCAPED_SLASHES), $url));
        $path = parse_url($url, PHP_URL_PATH);
        $this->assertSame(['email' => false], ['email' => in_array('email', $api()->getJson("/api/portail/partners/$client")->json('channels'), true)]);

        // Le client ouvre son espace (sans être connecté)
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get($path)->assertOk()->assertSee('BTP Kouassi &amp; Fils', false)->assertSee($inv->number)->assertSee('59 000 F CFA')->assertSee($quote->number);
        $this->get($path.'/factures')->assertOk()->assertSee($inv->number)->assertDontSee($foreign->number);
        $this->get($path.'/document/'.$foreign->id)->assertNotFound();
        $this->get($path.'/document/'.$draft)->assertNotFound();
        $this->get($path.'/document/'.$inv->id)->assertOk()->assertSee('Reste à payer');
        $this->get($path.'/document/'.$inv->id.'/payer')->assertRedirectContains('/pay/');
        $this->get($path.'/releve')->assertOk()->assertSee('Solde dû : 59 000 F CFA', false);
        $this->get('/portail/'.str_repeat('x', 48))->assertNotFound();

        // Devis : accord en ligne → commande confirmée + notification
        $this->get($path.'/devis')->assertOk()->assertSee('En attente de votre accord');
        $this->post($path.'/document/'.$quote->id.'/accepter', ['signer' => 'Koffi Kouassi'])->assertSessionHasErrors('agree');
        $this->post($path.'/document/'.$quote->id.'/accepter', ['signer' => 'Koffi Kouassi, gérant', 'agree' => '1'])->assertRedirect();
        $order = SalesDocument::withoutGlobalScopes()->where('source_id', $quote->id)->first();
        $this->assertSame('order', $order->type);
        $this->assertSame('validated', $order->status);
        $this->get($path.'/document/'.$quote->id)->assertOk()->assertSee('Bon pour accord')->assertSee('Koffi Kouassi, gérant')->assertDontSee('Accepter le devis');
        $this->post($path.'/document/'.$quote->id.'/accepter', ['signer' => 'X', 'agree' => '1'])->assertSessionHas('error');
        $this->assertTrue(AppNotification::where('company_id', $company->id)->where('type', 'portal')->where('title', 'like', '%'.$quote->number.'%')->exists());

        // Message du client, réponse de l'équipe
        $this->post($path.'/messages', ['subject' => 'Livraison du ciment', 'body' => 'Pouvez-vous livrer samedi ?', 'document_id' => $inv->id])->assertRedirect();
        $inbox = $api()->getJson('/api/portail/inbox')->assertOk()->json();
        $this->assertSame(1, $inbox['unread']);
        $this->assertSame(1, $inbox['accesses']);
        $api()->postJson("/api/portail/partners/$client/reply", ['body' => 'Oui, samedi matin.'])->assertOk();
        $this->assertSame(0, $api()->getJson('/api/portail/inbox')->json('unread'));
        $events = collect($api()->getJson("/api/portail/partners/$client")->json('events'))->pluck('type');
        $this->assertTrue($events->contains('quote_accepted') && $events->contains('message') && $events->contains('reply') && $events->contains('login'));
        $this->flushHeaders();
        $this->get($path.'/messages')->assertOk()->assertSee('Oui, samedi matin.');

        // Lien perdu : renvoyé par WhatsApp au numéro connu (même réponse si inconnu)
        $sent = count(Http::recorded());
        $this->post('/portail', ['contact' => '+225 0708091011'])->assertRedirect('/portail');
        $this->assertSame($sent + 1, count(Http::recorded()));
        $this->post('/portail', ['contact' => 'inconnu@nulle.part'])->assertRedirect('/portail');
        $this->assertSame($sent + 1, count(Http::recorded()));

        // Révocation : l'ancien lien ne marche plus
        $api()->postJson("/api/portail/partners/$client/revoke")->assertOk()->assertJsonPath('access.active', false);
        $this->flushHeaders();
        $this->get($path)->assertNotFound();
        $new = $api()->postJson("/api/portail/partners/$client/invite", ['channel' => 'none'])->assertOk()->json();
        $this->assertNull($new['sent_via']);
        $this->assertNotSame($url, $new['url']);
        $this->assertSame(1, PortalAccess::withoutGlobalScopes()->where('company_id', $company->id)->count());

        // Le nouveau lien fonctionne ; un livreur ne gère pas le portail
        $this->flushHeaders();
        $this->get(parse_url($new['url'], PHP_URL_PATH))->assertOk();
        $driver = \App\Models\User::create(['company_id' => $company->id, 'name' => 'Livreur', 'email' => 'liv@port.ci', 'password' => 'secret123',
            'role_id' => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Livreur')->value('id')]);
        $this->api($driver->createToken('d')->plainTextToken)->postJson("/api/portail/partners/$client/invite")->assertForbidden();
    }
}
