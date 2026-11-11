<?php

namespace Tests\Feature;

use App\Models\PlatformSetting;
use App\Support\EnvSettings;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Configuration du serveur depuis l'application : remplace le .env, secrets chiffrés et masqués. */
class EnvSettingsTest extends TestCase
{
    public function test_server_settings_override_env_and_mask_secrets(): void
    {
        [, , $token] = $this->makeCompany('Plateforme', 'entreprise', true);
        [, , $other] = $this->makeCompany('Client SARL');
        $this->api($other)->getJson('/api/admin/system/env')->assertForbidden();

        $v = $this->api($token)->getJson('/api/admin/system/env')->assertOk()->json('data');
        $this->assertContains('E-mail d\'envoi', collect($v['groups'])->pluck('name'));
        $this->assertSame('ERP_MODE', $v['locked'][0]['key']);

        $this->api($token)->putJson('/api/admin/system/env', ['values' => ['APP_DEBUG' => 'peut-être']])->assertStatus(422)->assertJsonStructure(['errors' => ['APP_DEBUG']]);
        $this->api($token)->putJson('/api/admin/system/env', ['values' => ['MAIL_PORT' => '465x']])->assertStatus(422);
        $this->api($token)->putJson('/api/admin/system/env', ['values' => ['INCONNU' => 'x']])->assertStatus(422);
        $d = $this->api($token)->putJson('/api/admin/system/env', ['values' => [
            'MAIL_HOST' => 'mail.yomaratechnologies.com', 'MAIL_PORT' => '465', 'MAIL_SCHEME' => 'smtps', 'MAIL_FROM_ADDRESS' => 'erp@yomaratechnologies.com',
            'AI_API_KEY' => 'sk-ant-secret-1234', 'ERP_TRIAL_DAYS' => '30', 'APP_URL' => 'https://erp.yomaratechnologies.com', 'ERP_UPDATE_PUBLISHER' => true,
        ]])->assertOk()->json('data');
        $fields = collect($d['groups'])->flatMap(fn ($g) => $g['fields'])->keyBy('key');
        $this->assertSame('settings', $fields['MAIL_HOST']['source']);
        $this->assertNull($fields['AI_API_KEY']['value']); // jamais renvoyé
        $this->assertSame('••••••1234', $fields['AI_API_KEY']['masked']);
        $this->assertStringNotContainsString('sk-ant', (string) PlatformSetting::find('env.AI_API_KEY')->getRawOriginal('value')); // chiffré en base

        // au démarrage : la configuration est relue avec ces valeurs
        EnvSettings::apply();
        $this->assertSame('mail.yomaratechnologies.com', config('mail.mailers.smtp.host'));
        $this->assertSame('sk-ant-secret-1234', config('erp.ai.api_key'));
        $this->assertSame(30, config('erp.trial_days'));
        $this->assertSame('https://erp.yomaratechnologies.com', config('app.url'));
        $this->assertTrue(config('erp.updates.publisher'));
        config(['erp.two_factor.super_admin' => false]); // relu par apply() : la double authentification est testée ailleurs

        // secret laissé vide = inchangé ; chaîne vide sur un champ = retour au .env
        $this->api($token)->putJson('/api/admin/system/env', ['values' => ['AI_API_KEY' => '', 'MAIL_HOST' => '']])->assertOk();
        $this->assertNull(PlatformSetting::find('env.MAIL_HOST'));
        $this->assertNotNull(PlatformSetting::find('env.AI_API_KEY'));
        $this->api($token)->putJson('/api/admin/system/env', ['values' => ['AI_API_KEY' => '__clear__']])->assertOk();
        $this->assertNull(PlatformSetting::find('env.AI_API_KEY'));

        Mail::fake();
        $this->api($token)->postJson('/api/admin/system/env/test-mail', ['to' => 'moi@yomaratechnologies.com'])->assertOk()->assertJsonPath('ok', true);
        $this->api($token)->postJson('/api/admin/system/env/test-mail', ['to' => 'pas-une-adresse'])->assertStatus(422);
    }
}
