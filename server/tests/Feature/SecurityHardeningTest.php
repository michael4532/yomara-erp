<?php

namespace Tests\Feature;

use App\Licensing\LicenseSigner;
use App\Models\Role;
use App\Models\User;
use App\Support\HtmlSanitizer;
use App\Support\UrlGuard;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Failles corrigées en 0.99.3 : chaque test rejoue l'attaque et vérifie qu'elle échoue. */
class SecurityHardeningTest extends TestCase
{
    public function test_user_manager_cannot_escalate_to_admin(): void
    {
        [$c, $admin, $adminToken] = $this->makeCompany('Esc');
        $role = Role::create(['company_id' => $c->id, 'name' => 'RH', 'permissions' => ['core.users.manage', 'core.contacts.view']]);
        $rh = User::create(['company_id' => $c->id, 'role_id' => $role->id, 'name' => 'RH', 'email' => 'rh@esc.ci', 'password' => 'secret123', 'is_active' => true]);
        $t = $rh->createToken('t')->plainTextToken;

        // Rôle « tous les droits », droit inconnu, prise du compte administrateur : refusés
        $this->api($t)->postJson('/api/roles', ['name' => 'Moi', 'permissions' => ['*']])->assertStatus(422);
        $this->api($t)->postJson('/api/roles', ['name' => 'Moi', 'permissions' => ['ventes.manage']])->assertForbidden();
        $this->api($t)->putJson('/api/users/'.$admin->id, ['email' => 'pirate@x.ci', 'password' => 'Pirate123'])->assertForbidden();
        $this->api($t)->postJson('/api/users', ['name' => 'X', 'email' => 'x@esc.ci', 'password' => 'secret123', 'role_id' => $admin->role_id])->assertForbidden();
        $this->assertSame($admin->email, $admin->fresh()->email);

        // Ce qu'il possède lui-même reste délégable ; l'administrateur garde la main sur tout
        $this->api($t)->postJson('/api/roles', ['name' => 'Lecture', 'permissions' => ['core.contacts.view']])->assertCreated();
        $this->api($adminToken)->postJson('/api/roles', ['name' => 'Ventes', 'permissions' => ['ventes.*']])->assertCreated();
    }

    public function test_html_sanitizer_blocks_known_bypasses(): void
    {
        foreach (['<img src="x"/onerror=alert(1)>', '<a href="java&#x09;script:alert(1)">x</a>', '<svg onload=alert(1)>', '<iframe srcdoc="x"></iframe>',
            '<meta http-equiv="refresh" content="0;url=https://evil">', '<div style="background:url(javascript:alert(1))">', '<form action="https://evil"><input></form>'] as $bad) {
            $out = strtolower(HtmlSanitizer::clean($bad));
            foreach (['onerror', 'onload', 'javascript:', '<iframe', '<svg', 'http-equiv', '<form', '<script'] as $needle) {
                $this->assertStringNotContainsString($needle, $out, $bad);
            }
        }
        $ok = HtmlSanitizer::clean('<p style="color:#123">Bonjour {{prenom}}</p><a href="{{lien_boutique}}">Voir</a>');
        $this->assertStringContainsString('href="{{lien_boutique}}"', $ok);
        $this->assertStringContainsString('style="color:#123"', $ok);
    }

    public function test_internal_addresses_are_refused(): void
    {
        foreach (['http://127.0.0.1/', 'http://localhost:8000', 'http://169.254.169.254/latest', 'http://10.0.0.5', 'http://192.168.1.1', 'ftp://example.com', 'http://user:p@example.com'] as $u) {
            $this->assertFalse(UrlGuard::isPublic($u), $u);
        }
        [, , $t] = $this->makeCompany('Hook');
        $this->api($t)->postJson('/api/integrations/webhooks', ['url' => 'http://127.0.0.1:3306/', 'events' => ['invoice.paid']])->assertStatus(422);
    }

    public function test_forged_license_from_another_key_is_rejected(): void
    {
        LicenseSigner::publicKey(); // clé officielle (de test)
        $this->assertTrue(LicenseSigner::ready());
        $kp = sodium_crypto_sign_keypair();
        $json = json_encode(['key' => 'YMR-FAKE', 'enabled' => ['paie', 'compta']]);
        $forged = base64_encode(sodium_crypto_sign_detached($json, sodium_crypto_sign_secretkey($kp)));
        // Même accompagnée de sa propre clé publique, la signature d'un autre serveur est refusée
        $this->assertFalse(LicenseSigner::verify($json, $forged, base64_encode(sodium_crypto_sign_publickey($kp))));
        $this->assertTrue(LicenseSigner::verify($json, LicenseSigner::signMessage($json)));
        $this->assertFalse(LicenseSigner::unlock('YED-MAUVAISE-CLE'));
    }

    public function test_shared_attachment_keeps_a_safe_extension(): void
    {
        [, , $t] = $this->makeCompany('Up');
        $html = UploadedFile::fake()->createWithContent('page.html', "%PDF-1.4\n<script>alert(document.domain)</script>");
        $r = $this->api($t)->postJson('/api/messaging/send', ['channel' => 'sms', 'to' => '+2250700000000', 'message' => 'Doc', 'attachment' => $html]);
        $this->assertNotSame(200, $r->status() === 200 && str_contains((string) $r->getContent(), '.html') ? 200 : 0);
        $this->assertEmpty(glob(public_path('uploads/shared/*/*.html')) ?: []);
    }

    public function test_promo_code_once_per_company_even_for_a_colleague(): void
    {
        \App\Models\Plan::where('code', 'gratuit')->get()->each->update(['modules' => ['ventes']]); // CRM hors du socle gratuit
        config(['erp.mode' => 'saas']);
        [, , $sa] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [$c, $owner, $t1] = $this->makeCompany('Boutique', 'starter');
        \App\Models\Module::where('key', 'crm')->update(['billing_mode' => 'both', 'price_monthly' => 10000]);
        \App\Models\Plan::where('code', 'starter')->update(['modules' => ['ventes']]);
        $bank = $this->api($sa)->postJson('/api/admin/payment-gateways', ['driver' => 'bank_transfer', 'label' => 'Virement', 'instructions' => 'RIB'])->json('id');
        $this->api($sa)->postJson('/api/admin/promo-codes', ['code' => 'UNEFOIS', 'type' => 'percent', 'value' => 50, 'once_per_user' => true])->assertCreated();
        $colleague = User::create(['company_id' => $c->id, 'role_id' => $owner->role_id, 'name' => 'Collègue', 'email' => 'col@b.ci', 'password' => 'secret123', 'is_active' => true]);
        $t2 = $colleague->createToken('t')->plainTextToken;

        $this->api($t1)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank, 'promo_code' => 'UNEFOIS'])->assertCreated();
        $this->api($t2)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank, 'promo_code' => 'UNEFOIS'])->assertStatus(422);
    }

    public function test_password_change_logs_out_other_devices(): void
    {
        [, $u, $t] = $this->makeCompany('Pwd');
        $other = $u->createToken('autre')->plainTextToken;
        $this->api($t)->postJson('/api/auth/password', ['current_password' => 'secret123', 'password' => 'Nouveau123', 'password_confirmation' => 'Nouveau123'])->assertOk();
        $this->assertSame(1, $u->tokens()->count());
    }
}
