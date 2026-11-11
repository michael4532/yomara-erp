<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Double authentification : activation, connexion en deux étapes, codes de secours, appareil de confiance, obligation. */
class TwoFactorTest extends TestCase
{
    protected function login(string $email, array $extra = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Accept' => 'application/json'])->postJson('/api/auth/login', ['email' => $email, 'password' => 'secret123'] + $extra);
    }

    public function test_totp_matches_rfc_vectors(): void
    {
        // RFC 6238, secret « 12345678901234567890 » (SHA1), 8 derniers chiffres → nos 6 derniers
        $secret = Totp::base32('12345678901234567890');
        $this->assertSame('287082', Totp::code($secret, intdiv(59, 30)));
        $this->assertSame('005924', Totp::code($secret, intdiv(1234567890, 30)));
        $this->assertNotNull(Totp::verify($secret, '287082', 59));
        $this->assertNull(Totp::verify($secret, '287082', 59 + 120));
    }

    public function test_app_method_full_flow(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Secu SARL');
        $api = fn () => $this->api($token);

        // Activation : mot de passe exigé, puis premier code
        $api()->postJson('/api/auth/2fa/setup', ['method' => 'app', 'password' => 'mauvais'])->assertStatus(422);
        $setup = $api()->postJson('/api/auth/2fa/setup', ['method' => 'app', 'password' => 'secret123'])->assertOk()->json();
        $this->assertStringStartsWith('otpauth://totp/', $setup['uri']);
        $api()->postJson('/api/auth/2fa/confirm', ['code' => '000000'])->assertStatus(422);
        $codes = $api()->postJson('/api/auth/2fa/confirm', ['code' => Totp::code($setup['secret'], intdiv(time(), 30))])->assertOk()->json('recovery_codes');
        $this->assertCount(8, $codes);
        $this->assertTrue($api()->getJson('/api/auth/me')->json('user.two_factor'));
        // Le secret ne sort jamais de l'API
        $this->assertStringNotContainsString('two_factor_secret', $api()->getJson('/api/users')->getContent());

        // Connexion : pas de jeton avant le code
        $r = $this->login($admin->email)->assertOk()->json();
        $this->assertTrue($r['two_factor']);
        $this->assertArrayNotHasKey('token', $r);
        $this->postJson('/api/auth/2fa/verify', ['challenge' => $r['challenge'], 'code' => '123456'])->assertStatus(422);
        // Le code déjà utilisé à l'activation ne peut pas être rejoué : on prend le pas suivant
        $next = Totp::code($setup['secret'], intdiv(time(), 30) + 1);
        $ok = $this->postJson('/api/auth/2fa/verify', ['challenge' => $r['challenge'], 'code' => $next, 'trust_device' => true])->assertOk()->json();
        $this->assertNotEmpty($ok['token']);
        $this->assertNotEmpty($ok['trust_token']);
        // Défi consommé
        $this->postJson('/api/auth/2fa/verify', ['challenge' => $r['challenge'], 'code' => $next])->assertStatus(422);

        // Appareil de confiance : connexion directe ; jeton invalide → code demandé
        $this->assertArrayHasKey('token', $this->login($admin->email, ['trust_token' => $ok['trust_token']])->json());
        $this->assertTrue($this->login($admin->email, ['trust_token' => $ok['trust_token'].'x'])->json('two_factor'));

        // Code de secours : une seule fois
        $r2 = $this->login($admin->email)->json();
        $this->postJson('/api/auth/2fa/verify', ['challenge' => $r2['challenge'], 'code' => strtolower($codes[0])])->assertOk()->assertJsonPath('recovery_left', 7);
        $r3 = $this->login($admin->email)->json();
        $this->postJson('/api/auth/2fa/verify', ['challenge' => $r3['challenge'], 'code' => $codes[0]])->assertStatus(422);

        // Trop d'essais : défi détruit
        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/auth/2fa/verify', ['challenge' => $r3['challenge'], 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/auth/2fa/verify', ['challenge' => $r3['challenge'], 'code' => $codes[1]])->assertStatus(422)->assertJsonFragment(['code' => ['Trop d\'essais : reconnectez-vous.']]);
        $this->postJson('/api/auth/2fa/verify', ['challenge' => $r3['challenge'], 'code' => $codes[1]])->assertStatus(422)->assertJsonFragment(['code' => ['Code expiré : reconnectez-vous.']]);

        // Changer le mot de passe retire la confiance aux appareils
        $admin->refresh()->update(['password' => 'nouveau123']);
        $this->app['auth']->forgetGuards();
        $this->assertTrue($this->withHeaders(['Accept' => 'application/json'])->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'nouveau123', 'trust_token' => $ok['trust_token']])->json('two_factor'));
    }

    public function test_email_method_and_admin_reset(): void
    {
        Mail::fake();
        [$company, $admin, $token] = $this->makeCompany('Mail SARL');
        $user = User::create(['company_id' => $company->id, 'name' => 'Awa', 'email' => 'awa@mail.ci', 'password' => 'secret123']);
        $uapi = fn () => $this->api($user->createToken('u')->plainTextToken);

        $s = $uapi()->postJson('/api/auth/2fa/setup', ['method' => 'email', 'password' => 'secret123'])->assertOk()->json();
        $this->assertStringContainsString('@mail.ci', $s['email_hint']);
        $code = $this->lastCode($user->id, '2fa:setup:');
        $uapi()->postJson('/api/auth/2fa/confirm', ['code' => $code])->assertOk();

        // Connexion : le code part par e-mail automatiquement, renvoi possible
        $r = $this->login('awa@mail.ci')->assertOk()->json();
        $this->assertSame('email', $r['method']);
        $this->postJson('/api/auth/2fa/send', ['challenge' => $r['challenge']])->assertOk();
        $ch = Cache::get('2fa:ch:'.hash('sha256', $r['challenge']));
        $this->assertSame(2, $ch['sent']);

        // Téléphone / boîte perdue : l'administrateur réinitialise, toutes les sessions sont fermées
        $this->api($token)->postJson("/api/users/{$user->id}/2fa/reset")->assertOk()->assertJsonPath('data.two_factor', false);
        $this->assertSame(0, $user->tokens()->count());
        $this->assertArrayHasKey('token', $this->login('awa@mail.ci')->json());
    }

    public function test_required_setup_blocks_until_enabled(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Exige SARL');
        $user = User::create(['company_id' => $company->id, 'name' => 'Koffi', 'email' => 'koffi@exige.ci', 'password' => 'secret123']);
        $this->api($token)->putJson('/api/company', ['settings' => ['require_2fa' => true]])->assertOk();

        $ut = $this->login('koffi@exige.ci')->json('token');
        $this->api($ut)->getJson('/api/auth/me')->assertOk()->assertJsonPath('user.two_factor_required', true);
        $this->api($ut)->getJson('/api/bootstrap')->assertStatus(403)->assertJsonPath('code', 'two_factor_setup_required');
        $this->api($ut)->getJson('/api/ventes/documents')->assertStatus(403);
        // Ne peut pas désactiver une fois active
        $s = $this->api($ut)->postJson('/api/auth/2fa/setup', ['method' => 'app', 'password' => 'secret123'])->json();
        $this->api($ut)->postJson('/api/auth/2fa/confirm', ['code' => Totp::code($s['secret'], intdiv(time(), 30))])->assertOk();
        $this->api($ut)->getJson('/api/bootstrap')->assertOk();
        $this->api($ut)->postJson('/api/auth/2fa/disable', ['password' => 'secret123'])->assertStatus(422);

        // Super administrateur : obligatoire quand la plateforme l'exige
        config(['erp.two_factor.super_admin' => true]);
        [, $sa, $st] = $this->makeCompany('Plateforme', 'entreprise', true);
        $this->api($st)->getJson('/api/core/partners')->assertStatus(403);
        // Les clés d'API d'intégration ne sont pas bloquées
        $key = $sa->createToken('api:compta', ['read'])->plainTextToken;
        $this->api($key)->getJson('/api/core/partners')->assertOk();
    }

    /** Code e-mail en attente : on le régénère ici en lisant le hash (les tests n'ont pas accès au code en clair). */
    protected function lastCode(int $userId, string $prefix): string
    {
        $p = Cache::get($prefix.$userId);
        for ($i = 0; $i <= 999999; $i++) {
            $c = str_pad((string) $i, 6, '0', STR_PAD_LEFT);
            if (hash('sha256', $c) === $p['code']) {
                return $c;
            }
        }
        $this->fail('code introuvable');
    }
}
