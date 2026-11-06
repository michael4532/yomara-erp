<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Audit de contrôle du 6 novembre 2026 (second passage) : chaque test rejoue l'attaque et vérifie qu'elle ne passe plus. */
class SecurityAudit4Test extends TestCase
{
    public function test_supplier_invoice_file_with_html_content_is_never_served_as_html(): void
    {
        Storage::fake('local');
        [, , $token] = $this->makeCompany('Acheteur');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $sup = $api()->postJson('/api/core/partners', ['name' => 'Fournisseur Pirate', 'type' => 'supplier', 'email' => 'pirate@fourn.ci'])->json('data.id');
        $url = $api()->postJson('/api/portail_fournisseurs/partners/'.$sup.'/invite', ['send' => false])->assertOk()->json('url');
        $portal = parse_url($url, PHP_URL_PATH);

        // Le fournisseur (externe, sans compte) dépose une « image » qui est en réalité une page HTML
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->post($portal.'/factures', ['number' => 'F-XSS', 'date' => now()->toDateString(), 'amount' => 1000,
            'file' => UploadedFile::fake()->createWithContent('facture.png', '<html><body><script>alert(document.cookie)</script></body></html>')])->assertRedirect();
        $id = \App\Modules\Fournisseurs\Models\SupplierInvoice::withoutGlobalScopes()->where('number', 'F-XSS')->value('id');
        $this->assertNotNull($id);

        $res = $api()->get('/api/portail_fournisseurs/invoices/'.$id.'/file')->assertOk();
        $this->assertStringNotContainsString('html', strtolower((string) $res->headers->get('Content-Type')));
        $this->assertSame('image/png', $res->headers->get('Content-Type'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', (string) $res->headers->get('Content-Security-Policy'));
    }

    public function test_company_smtp_server_cannot_target_the_internal_network_in_saas(): void
    {
        config(['erp.mode' => 'saas']);
        \App\Messaging\Providers\Smtp::$transportFactory = null;
        [, , $token] = $this->makeCompany('Expéditeur');
        $set = fn (string $host) => $this->api($token)->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => $host, 'port' => '25', 'encryption' => 'none', 'from_email' => 'contact@exemple.ci']])->assertOk();

        $set('127.0.0.1');
        $this->api($token)->postJson('/api/messaging/test', ['channel' => 'email', 'to' => 'dest@exemple.ci'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Échec : Serveur refusé : 127.0.0.1 désigne une adresse interne.']);

        $set('169.254.169.254');
        $this->api($token)->postJson('/api/messaging/test', ['channel' => 'email', 'to' => 'dest@exemple.ci'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Échec : Serveur refusé : 169.254.169.254 désigne une adresse interne.']);

        // Pas d'injection d'options dans l'adresse du serveur
        $set('mail.exemple.ci:25?local_domain=x#');
        $this->api($token)->postJson('/api/messaging/test', ['channel' => 'email', 'to' => 'dest@exemple.ci'])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Échec : Serveur SMTP invalide.']);
    }
}
