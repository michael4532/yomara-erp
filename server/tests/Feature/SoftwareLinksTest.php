<?php

namespace Tests\Feature;

use Tests\TestCase;

class SoftwareLinksTest extends TestCase
{
    public function test_store_and_file_links_per_platform(): void
    {
        config(['erp.mode' => 'saas']);
        [, , $admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        $play = 'https://play.google.com/store/apps/details?id=com.yomaratechnologies.yomara_erp';
        $apk = 'https://cdn.example.com/yomara-erp.apk';
        $repo = 'https://github.com/yomara/yomara-erp';

        $s = $this->api($admin)->getJson('/api/admin/store/settings')->assertOk()->json();
        $this->assertSame($play, $s['official_links']['android_store']);
        $this->flushHeaders();

        $this->api($admin)->putJson('/api/admin/store/settings', ['downloads' => ['android' => $apk, 'android_store' => $play, 'github' => $repo], 'apps_version' => '1.0.0'])->assertOk();
        $this->flushHeaders();
        // une ancienne application qui n'envoie que les anciennes clés n'efface pas les nouvelles
        $this->api($admin)->putJson('/api/admin/store/settings', ['downloads' => ['android' => $apk]])->assertOk();
        $this->flushHeaders();
        $s = $this->api($admin)->getJson('/api/admin/store/settings')->json();
        $this->assertSame($play, $s['downloads']['android_store']);
        $this->assertSame('1.0.0', $s['apps_version']);
        $this->flushHeaders();

        $this->get('/telecharger')->assertOk()->assertSee('Google Play')->assertSee('/telecharger/android/fichier', false)->assertSee('Version 1.0.0')->assertSee('/telecharger/github', false);
        $this->get('/telecharger/android')->assertRedirect($play);
        $this->get('/telecharger/android/fichier')->assertRedirect($apk);
        $this->get('/telecharger/github')->assertRedirect($repo);
        $this->get('/telecharger/source')->assertOk()->assertSee($repo, false);
        $this->get('/telecharger/windows/fichier')->assertRedirect('/telecharger'); // rien de renseigné
        $this->get('/telecharger/ios/fichier')->assertNotFound();

        $this->api($admin)->putJson('/api/admin/store/settings', ['downloads' => ['github' => 'javascript:alert(1)']])->assertStatus(422);
    }
}
