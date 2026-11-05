<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\User;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    public function test_ping_and_login_and_bootstrap(): void
    {
        $this->getJson('/api/ping')->assertOk()->assertJsonPath('product', 'yomara-erp');

        [$c, $u] = $this->makeCompany('Alpha');
        $res = $this->withHeader('X-Client-Platform', 'macos')
            ->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'secret123', 'device_name' => 'MacBook'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['id', 'name']]);

        $boot = $this->api($res->json('token'), 'macos')->getJson('/api/bootstrap')->assertOk();
        $this->assertContains('ventes', $boot->json('enabled_modules'));
        $this->assertContains('stock', $boot->json('enabled_modules'));
        $this->assertContains('sales_documents', $boot->json('sync_entities'));
        $this->assertSame('macos', $u->fresh()->last_platform);

        $this->postJson('/api/auth/login', ['email' => $u->email, 'password' => 'mauvais'])->assertStatus(422);
    }

    public function test_super_admin_sets_price_and_disables_module_globally(): void
    {
        [, , $adminToken] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [, , $clientToken] = $this->makeCompany('Client');

        $this->api($adminToken)->putJson('/api/admin/modules/ventes', ['price_monthly' => 12500, 'price_yearly' => 125000])
            ->assertOk()->assertJsonPath('data.price_monthly', 12500);

        $this->api($clientToken)->getJson('/api/ventes/documents')->assertOk();

        $this->api($adminToken)->putJson('/api/admin/modules/ventes', ['is_active' => false])->assertOk();
        $this->api($clientToken)->getJson('/api/ventes/documents')->assertStatus(403)->assertJsonPath('code', 'module_disabled');
        $this->assertNotContains('ventes', $this->api($clientToken)->getJson('/api/bootstrap')->json('enabled_modules'));

        // Le module de base ne peut pas être coupé
        $this->api($adminToken)->putJson('/api/admin/modules/core', ['is_active' => false])->assertStatus(422);
        // Un client normal n'a pas accès au super admin
        $this->api($clientToken)->getJson('/api/admin/modules')->assertStatus(403);
    }

    public function test_super_admin_toggles_module_for_one_company_with_dependencies(): void
    {
        [, , $adminToken] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [$client, , $clientToken] = $this->makeCompany('Client');

        $this->api($adminToken)->putJson("/api/admin/companies/{$client->id}/modules/stock", ['enabled' => false])->assertOk();
        $this->api($clientToken)->getJson('/api/stock/levels')->assertStatus(403);
        // pos dépend de stock → coupé aussi
        $this->assertNotContains('pos', $client->fresh()->enabledModules());

        $this->api($adminToken)->putJson("/api/admin/companies/{$client->id}/modules/stock", ['enabled' => true, 'price_override' => 5000])->assertOk();
        $this->api($clientToken)->getJson('/api/stock/levels')->assertOk();

        $mods = collect($this->api($adminToken)->getJson("/api/admin/companies/{$client->id}/modules")->json('data'))->keyBy('key');
        $this->assertEquals(5000, $mods['stock']['price_monthly']);
        $this->assertTrue($mods['stock']['enabled']);
    }

    public function test_saas_mode_uses_plan_modules_and_registration(): void
    {
        config(['erp.mode' => 'saas']);

        $res = $this->postJson('/api/auth/register', [
            'company_name' => 'Boutique Kouassi', 'name' => 'Kouassi', 'email' => 'k@boutique.ci',
            'password' => 'motdepasse', 'plan' => 'starter',
        ])->assertCreated();

        $enabled = $this->api($res->json('token'))->getJson('/api/bootstrap')->json('enabled_modules');
        // Starter : facturation, achats, stock, caisse, clients, dépenses (+ agenda, module gratuit inclus dans toutes les formules)
        $this->assertEqualsCanonicalizing(['core', 'ventes', 'achats', 'stock', 'pos', 'crm', 'tresorerie', 'migration', 'fne', 'relances', 'etiquettes', 'import_export', 'agenda', 'activite'], $enabled);
        $this->api($res->json('token'))->getJson('/api/compta/setup')->assertStatus(403);
    }

    public function test_online_users_are_counted_for_super_admin(): void
    {
        [, , $adminToken] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [, $u1, $t1] = $this->makeCompany('A');
        [, , $t2] = $this->makeCompany('B');
        [, $offline] = $this->makeCompany('C');

        $this->api($t1, 'windows')->postJson('/api/presence/ping')->assertOk();
        $this->api($t2, 'linux')->postJson('/api/presence/ping')->assertOk();
        $this->api($adminToken, 'web')->getJson('/api/admin/overview')->assertOk();

        $online = $this->api($adminToken, 'web')->getJson('/api/admin/online')->assertOk();
        $this->assertSame(3, $online->json('count'));
        $platforms = collect($online->json('data'))->pluck('platform')->sort()->values()->all();
        $this->assertSame(['linux', 'web', 'windows'], $platforms);

        $overview = $this->api($adminToken)->getJson('/api/admin/overview')->json();
        $this->assertSame(3, $overview['users']['online']);
        $this->assertSame(1, $overview['users']['online_by_platform']['linux']);

        // Déconnexion → ne compte plus
        $this->api($t1)->postJson('/api/auth/logout')->assertOk();
        $this->assertSame(2, $this->api($adminToken)->getJson('/api/admin/online')->json('count'));
    }

    public function test_suspended_company_is_blocked(): void
    {
        [, , $adminToken] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [$client, , $clientToken] = $this->makeCompany('Client');

        $this->api($adminToken)->putJson("/api/admin/companies/{$client->id}", ['status' => 'suspended'])->assertOk();
        // tokens révoqués
        $this->api($clientToken)->getJson('/api/bootstrap')->assertStatus(401);
    }

    public function test_tenant_isolation(): void
    {
        [, , $ta] = $this->makeCompany('A');
        [, , $tb] = $this->makeCompany('B');

        $id = $this->api($ta)->postJson('/api/core/products', ['name' => 'Riz 25kg', 'sale_price' => 15000])->assertCreated()->json('data.id');
        $this->api($tb)->getJson("/api/core/products/$id")->assertNotFound();
        $this->assertSame(0, $this->api($tb)->getJson('/api/core/products')->json('total'));
        $this->assertSame(1, $this->api($ta)->getJson('/api/core/products')->json('total'));
    }

    public function test_permissions_are_enforced(): void
    {
        [$c, , $adminToken] = $this->makeCompany('A');
        $roles = collect($this->api($adminToken)->getJson('/api/roles')->json('data'))->keyBy('name');

        $this->api($adminToken)->postJson('/api/users', [
            'name' => 'Vendeur', 'email' => 'vendeur@a.ci', 'password' => 'secret123', 'role_id' => $roles['Lecture seule']['id'],
        ])->assertCreated();
        $token = User::where('email', 'vendeur@a.ci')->first()->createToken('t')->plainTextToken;

        $this->api($token)->getJson('/api/core/products')->assertOk();
        $this->api($token)->postJson('/api/core/products', ['name' => 'X'])->assertStatus(403);
        $this->api($token)->getJson('/api/users')->assertStatus(403);
    }
}
