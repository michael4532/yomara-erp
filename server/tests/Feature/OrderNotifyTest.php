<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Notifications de commande automatiques : accusé de réception avec ticket du service client, confirmation, annulation, équipe. */
class OrderNotifyTest extends TestCase
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
        parent::tearDown();
    }

    public function test_order_received_confirmed_cancelled(): void
    {
        [$company, $user, $token] = $this->makeCompany('Boutique Notif');
        $api = fn () => $this->api($token);
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.boutique.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'shop@boutique.ci', 'password' => 'x', 'from_email' => 'shop@boutique.ci', 'from_name' => 'Boutique',
        ]])->assertOk();

        // Réglages : actif par défaut pour les commandes en ligne ; on l'étend à toutes les commandes + alerte équipe
        $api()->getJson('/api/ventes/order-notifications')->assertOk()->assertJsonPath('data.enabled', true)->assertJsonPath('data.scope', 'online');
        $api()->putJson('/api/ventes/order-notifications', ['enabled' => true, 'scope' => 'all', 'team_emails' => ['ventes@boutique.ci'], 'texts' => ['received' => 'Votre commande N° {numero} a été reçue et est en cours d\'examen.']])
            ->assertOk()->assertJsonPath('data.scope', 'all');
        $api()->putJson('/api/ventes/order-notifications', ['team_emails' => ['pas-un-email']])->assertStatus(422);

        $client = $api()->postJson('/api/core/partners', ['name' => 'Awa Koné', 'type' => 'customer', 'email' => 'awa@gmail.com'])->json('data.id');
        $order = $api()->postJson('/api/ventes/documents', ['type' => 'order', 'partner_id' => $client, 'date' => now()->toDateString(), 'reference' => 'WEB-77',
            'lines' => [['description' => 'Ciment CPJ 45', 'quantity' => 3, 'unit_price' => 6000, 'tax_rate' => 0]]])->assertCreated()->json('data');

        $n = DB::table('order_notices')->where('document_id', $order['id'])->where('event', 'received')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('email', $n->channels);
        $mails = $this->mails->messages()->values();
        $toClient = $mails->first(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'awa@gmail.com')->getOriginalMessage();
        $this->assertStringContainsString('WEB-77', $toClient->getSubject());
        $this->assertStringContainsString('Ciment CPJ 45', $toClient->getHtmlBody());
        $this->assertStringContainsString('en cours d\'examen', html_entity_decode($toClient->getHtmlBody(), ENT_QUOTES));
        $this->assertTrue($mails->contains(fn ($m) => $m->getOriginalMessage()->getTo()[0]->getAddress() === 'ventes@boutique.ci'));

        // Ticket du service client si le module est actif : référence dans l'objet
        $this->assertSame($company->hasModule('helpdesk'), (bool) $n->ticket_id);
        if ($n->ticket_id) {
            $num = DB::table('hd_tickets')->where('id', $n->ticket_id)->value('number');
            $this->assertStringContainsString('['.$num.']', $toClient->getSubject());
        }

        // Pas de doublon ; confirmation puis annulation
        app(\App\Modules\Ventes\OrderNotifier::class)->notify($company->fresh(), \App\Modules\Ventes\Models\SalesDocument::withoutGlobalScopes()->find($order['id']), 'received');
        $this->assertSame(1, DB::table('order_notices')->where('document_id', $order['id'])->where('event', 'received')->count());
        $api()->postJson("/api/ventes/documents/{$order['id']}/validate")->assertOk();
        $this->assertTrue(DB::table('order_notices')->where('document_id', $order['id'])->where('event', 'confirmed')->exists());
        $this->assertTrue($this->mails->messages()->contains(fn ($m) => str_starts_with($m->getOriginalMessage()->getSubject(), 'Commande confirmée')));
        $api()->postJson("/api/ventes/documents/{$order['id']}/cancel")->assertOk();
        $this->assertTrue(DB::table('order_notices')->where('document_id', $order['id'])->where('event', 'cancelled')->exists());

        // Aperçu et renvoi manuel
        $api()->get('/api/ventes/order-notifications/preview?event=confirmed&id='.$order['id'])->assertOk()->assertSee('WEB-77');
        $api()->postJson("/api/ventes/order-notifications/send/{$order['id']}", ['event' => 'received'])->assertOk()->assertJsonPath('sent.0', 'email');
    }
}
