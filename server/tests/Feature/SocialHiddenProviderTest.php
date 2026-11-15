<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use Tests\TestCase;

/** Un réseau dont les clés sont saisies mais la configuration pas terminée peut être masqué aux clients. */
class SocialHiddenProviderTest extends TestCase
{
    public function test_hidden_network_is_invisible_to_clients_but_testable_by_super_admin(): void
    {
        [, , $admin] = $this->makeCompany('Plateforme', 'entreprise', true);
        [, , $client] = $this->makeCompany('Client');
        PlatformSetting::put('social.apps', ['x' => ['client_id' => 'cid', 'client_secret' => encrypt('s')], 'tiktok' => ['client_id' => 'ck', 'client_secret' => encrypt('s')]]);

        // Le super administrateur masque X et TikTok
        foreach (['x', 'tiktok'] as $p) {
            $this->api($admin)->putJson('/api/admin/social-apps/'.$p, ['client_id' => $p === 'x' ? 'cid' : 'ck', 'hidden' => true])
                ->assertOk()->assertJsonPath('providers.'.array_search($p, array_keys(\App\Modules\Social\Networks::PROVIDERS)).'.hidden', true);
        }

        $keys = collect($this->api($client)->getJson('/api/social/overview')->assertOk()->json('networks'))->pluck('key');
        $this->assertNotContains('x', $keys);
        $this->assertNotContains('tiktok', $keys);
        $this->assertContains('facebook', $keys);
        $this->api($client)->postJson('/api/social/connect/x')->assertStatus(422);

        // Le super administrateur les voit (marqués masqués) et peut se connecter pour tester ; les clés sont conservées
        $x = collect($this->api($admin)->getJson('/api/social/overview')->json('networks'))->firstWhere('key', 'x');
        $this->assertTrue($x['hidden']);
        $this->assertTrue($x['available']);
        $this->assertStringContainsString('x.com', $this->api($admin)->postJson('/api/social/connect/x')->assertOk()->json('url'));
        $this->assertNotEmpty(PlatformSetting::get('social.apps')['x']['client_secret']);

        // Réaffiché : de nouveau proposé aux clients
        $this->api($admin)->putJson('/api/admin/social-apps/x', ['client_id' => 'cid', 'hidden' => false])->assertOk();
        $this->assertContains('x', collect($this->api($client)->getJson('/api/social/overview')->json('networks'))->pluck('key'));
    }
}
