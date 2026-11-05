<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AuthController;
use App\Models\AppNotification;
use App\Support\Push;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Notifications push (jetons des appareils, envoi à chaque notification) et connexion Google facultative. */
class PushGoogleTest extends TestCase
{
    protected function tearDown(): void
    {
        Push::$sender = null;
        AuthController::$googleVerifier = null;
        parent::tearDown();
    }

    public function test_jeton_et_envoi_push(): void
    {
        $sent = [];
        Push::$sender = function ($m) use (&$sent) { $sent[] = $m; return str_starts_with($m['token'], 'gone') ? 'gone' : 'ok'; };
        [$c, $u, $token] = $this->makeCompany('Push SARL');
        $tok = str_repeat('a', 40);
        $this->api($token, 'android')->postJson('/api/me/push-token', ['token' => $tok, 'platform' => 'android'])->assertOk()->assertJson(['enabled' => true]);
        $this->api($token, 'android')->postJson('/api/me/push-token', ['token' => $tok, 'platform' => 'android'])->assertOk(); // pas de doublon
        $this->assertSame(1, DB::table('push_tokens')->where('user_id', $u->id)->count());
        DB::table('push_tokens')->insert(['user_id' => $u->id, 'company_id' => $c->id, 'token_hash' => hash('sha256', 'gone'.$tok), 'token' => 'gone'.$tok, 'platform' => 'ios', 'created_at' => now(), 'updated_at' => now()]);

        AppNotification::send($c->id, [$u->id], 'ventes', 'Nouvelle commande CMD-1', '25 000 F', 'ventes', '42');
        $this->assertSame(1, Push::flush()); // un envoi réussi, un jeton périmé
        $this->assertCount(2, $sent);
        $this->assertSame('Nouvelle commande CMD-1', $sent[0]['notification']['title']);
        $this->assertSame('42', $sent[0]['data']->ref);
        $this->assertSame(1, DB::table('push_tokens')->where('user_id', $u->id)->count()); // jeton expiré retiré

        $this->api($token)->postJson('/api/me/push-test')->assertOk()->assertJson(['devices' => 1, 'sent' => 1]);
        $this->api($token)->deleteJson('/api/me/push-token', ['token' => $tok])->assertNoContent();
        $this->assertSame(0, DB::table('push_tokens')->where('user_id', $u->id)->count());
    }

    public function test_sans_firebase_rien_n_est_envoye(): void
    {
        [$c, $u, $token] = $this->makeCompany('NoPush SARL');
        $this->assertFalse(Push::enabled());
        AppNotification::send($c->id, [$u->id], 'x', 'Titre');
        $this->assertSame(0, Push::flush());
        $this->api($token)->postJson('/api/me/push-test')->assertStatus(422);
    }

    public function test_cle_firebase_validee(): void
    {
        $this->assertFalse(\App\Support\EnvSettings::validServiceAccount('{"type":"x"}'));
        $this->assertTrue(\App\Support\EnvSettings::validServiceAccount(json_encode(['type' => 'service_account', 'project_id' => 'yomara-erp', 'client_email' => 'a@b.iam.gserviceaccount.com', 'private_key' => "-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----\n"])));
    }

    public function test_connexion_google(): void
    {
        [, $u] = $this->makeCompany('Google SARL');
        $aud = config('erp.google_signin.client_ids')[0];
        AuthController::$googleVerifier = fn ($t) => match ($t) {
            'good' => ['iss' => 'https://accounts.google.com', 'aud' => $aud, 'exp' => time() + 600, 'email_verified' => 'true', 'email' => strtoupper($u->email)],
            'stranger' => ['iss' => 'accounts.google.com', 'aud' => $aud, 'exp' => time() + 600, 'email_verified' => true, 'email' => 'inconnu@gmail.com'],
            'wrongaud' => ['iss' => 'accounts.google.com', 'aud' => 'autre-appli', 'exp' => time() + 600, 'email_verified' => true, 'email' => $u->email],
            default => null,
        };
        $this->getJson('/api/ping')->assertJson(['google_signin' => true]);
        $r = $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertOk();
        $this->assertSame($u->id, $r->json('user.id'));
        $this->assertNotEmpty($r->json('token'));
        $this->postJson('/api/auth/google', ['id_token' => 'stranger'])->assertStatus(422)->assertJsonFragment(['id_token' => ['Aucun compte avec l\'adresse inconnu@gmail.com. Connectez-vous avec votre e-mail professionnel, ou demandez à votre administrateur de vous créer un compte.']]);
        $this->postJson('/api/auth/google', ['id_token' => 'wrongaud'])->assertStatus(422);
        $u->forceFill(['is_active' => false])->save();
        $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertStatus(422);
        config(['erp.google_signin.enabled' => false]);
        $this->postJson('/api/auth/google', ['id_token' => 'good'])->assertNotFound();
    }
}
