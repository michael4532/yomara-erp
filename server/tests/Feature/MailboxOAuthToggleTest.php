<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Le super admin peut couper la connexion des boîtes Gmail et Outlook (Configuration du serveur). */
class MailboxOAuthToggleTest extends TestCase
{
    public function test_connexions_gmail_outlook_desactivables(): void
    {
        [, , $token] = $this->makeCompany('Boites SARL');
        $api = fn () => $this->api($token);
        $api()->postJson('/api/mailbox/accounts/detect', ['email' => 'jean@gmail.com'])->assertOk()->assertJson(['provider' => 'google', 'oauth' => true]);
        $this->assertSame(['microsoft' => false, 'google' => false], $api()->getJson('/api/mailbox')->assertOk()->json('data.oauth_disabled') ?? $api()->getJson('/api/mailbox')->json('oauth_disabled'));

        config(['erp.mailbox_oauth.google' => false, 'erp.mailbox_oauth.microsoft' => false]);
        // Gmail proposé en IMAP classique, plus de connexion Google
        $api()->postJson('/api/mailbox/accounts/detect', ['email' => 'jean@gmail.com'])->assertOk()->assertJsonPath('settings.imap_host', 'imap.gmail.com');
        $api()->postJson('/api/mailbox/oauth/google')->assertForbidden();
        $api()->postJson('/api/mailbox/oauth/microsoft')->assertForbidden();
        $d = $api()->getJson('/api/mailbox')->assertOk()->json();
        $this->assertSame(['microsoft' => true, 'google' => true], $d['data']['oauth_disabled'] ?? $d['oauth_disabled']);
    }
}
