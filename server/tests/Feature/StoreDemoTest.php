<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/** Compte de démonstration pour la relecture des stores, créé depuis l'administration. */
class StoreDemoTest extends TestCase
{
    public function test_super_admin_creates_store_demo_account_that_can_sign_in(): void
    {
        [, , $token] = $this->makeCompany('Éditeur', 'entreprise', true);
        $admin = fn () => $this->api($token);
        $admin()->getJson('/api/admin/store-demo')->assertOk()->assertJsonPath('data.exists', false);

        $r = $admin()->postJson('/api/admin/store-demo', ['email' => 'demo.stores@exemple.ci'])->assertCreated()->json('data');
        $this->assertTrue($r['exists']);
        $this->assertTrue($r['active']);
        $this->assertStringStartsWith('Demo-', $r['password']);
        $this->assertStringContainsString('demo.stores@exemple.ci', $r['review_notes']);
        $admin()->postJson('/api/admin/store-demo', ['email' => 'autre@exemple.ci'])->assertStatus(422);

        $u = User::where('email', 'demo.stores@exemple.ci')->first();
        $this->assertFalse((bool) $u->is_super_admin);
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('products')->where('company_id', $u->company_id)->count());
        $this->assertGreaterThan(0, \Illuminate\Support\Facades\DB::table('partners')->where('company_id', $u->company_id)->count());

        // connexion comme la relecture Apple / Google
        $login = $this->postJson('/api/auth/login', ['email' => 'demo.stores@exemple.ci', 'password' => $r['password']])->assertOk()->json('token');
        $demo = $this->api($login, 'ios');
        $demo->getJson('/api/admin/store-demo')->assertForbidden();
        $demo->postJson('/api/auth/password', ['current_password' => $r['password'], 'password' => 'nouveau123', 'password_confirmation' => 'nouveau123'])->assertStatus(422);

        // la société de démonstration n'entre pas dans le chiffre d'affaires
        $row = collect($admin()->getJson('/api/admin/companies')->json('data'))->firstWhere('id', $u->company_id);
        $this->assertTrue($row['is_store_demo']);
        $this->assertEquals(0, $row['monthly']);

        // après un test de suppression de compte : nouveau mot de passe = compte réactivé
        $u->forceFill(['is_active' => false])->save();
        $r2 = $admin()->postJson('/api/admin/store-demo/password')->assertOk()->json('data');
        $this->assertNotSame($r['password'], $r2['password']);
        $this->assertTrue($r2['active']);
        $this->postJson('/api/auth/login', ['email' => 'demo.stores@exemple.ci', 'password' => $r['password']])->assertStatus(422);
        $this->postJson('/api/auth/login', ['email' => 'demo.stores@exemple.ci', 'password' => $r2['password']])->assertOk();

        $admin()->postJson('/api/admin/store-demo/disable')->assertOk()->assertJsonPath('data.active', false);
        $this->postJson('/api/auth/login', ['email' => 'demo.stores@exemple.ci', 'password' => $r2['password']])->assertStatus(422);
        $admin()->postJson('/api/admin/store-demo/forget')->assertOk()->assertJsonPath('data.exists', false);
    }

    public function test_company_admin_cannot_use_store_demo_routes(): void
    {
        [, , $token] = $this->makeCompany('Client');
        $this->api($token)->getJson('/api/admin/store-demo')->assertForbidden();
        $this->api($token)->postJson('/api/admin/store-demo', ['email' => 'x@y.ci'])->assertForbidden();
    }
}
