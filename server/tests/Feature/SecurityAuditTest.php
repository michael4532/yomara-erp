<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\UrlGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Failles corrigées lors de l'audit de novembre 2026 : chaque test vérifie que l'attaque ne passe plus. */
class SecurityAuditTest extends TestCase
{
    public function test_internal_addresses_hidden_in_ipv6_are_refused(): void
    {
        foreach (['::ffff:127.0.0.1', '::ffff:169.254.169.254', '64:ff9b::7f00:1', '2002:7f00:1::', '100.64.1.1', '::1', 'fd00::1', '169.254.169.254', '10.0.0.1'] as $ip) {
            $this->assertFalse(UrlGuard::ipAllowed($ip), $ip);
        }
        $this->assertTrue(UrlGuard::ipAllowed('8.8.8.8'));
        $this->assertFalse(UrlGuard::isPublic('http://[::ffff:127.0.0.1]/'));
    }

    public function test_visual_studio_cannot_read_server_files(): void
    {
        [, , $token] = $this->makeCompany('Studio Sûr');
        $api = $this->api($token);
        foreach (['/etc/passwd', '../storage/app/private/x.jpg', 'file:///etc/hosts'] as $bad) {
            $api->postJson('/api/social/visuals/preview', ['template' => 'announce', 'title' => 'Test', 'image_url' => $bad])->assertStatus(422);
        }
        $this->assertNull(\App\Modules\Social\VisualRenderer::load('/etc/hostname'));
        $this->assertNull(\App\Modules\Social\VisualRenderer::load('../../.env'));
    }

    public function test_read_only_records_hidden_secrets_and_settings_whitelist(): void
    {
        [$company, , $token] = $this->makeCompany('Agence Sûre');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/acquisition/schema')->assertOk()->assertJsonPath('data.resources.leads.can_manage', false);
        // même l'administrateur (« tous les droits ») ne modifie pas le journal ni les listes d'un autre module par cette route
        $api()->postJson('/api/acquisition/records/leads', ['title' => 'x'])->assertForbidden();
        $api()->postJson('/api/acquisition/records/lists', ['name' => 'x'])->assertForbidden();
        $src = DB::table('ac_sources')->where('company_id', $company->id)->first();
        $this->assertNotNull($api()->getJson('/api/acquisition/records/sources/'.$src->id)->json('data.token'));
        // un utilisateur en lecture seule ne voit ni l'adresse secrète ni la clé
        $role = Role::create(['company_id' => $company->id, 'name' => 'Lecteur pub', 'permissions' => ['acquisition.view']]);
        $u = User::create(['company_id' => $company->id, 'role_id' => $role->id, 'name' => 'Lecteur', 'email' => 'lecteur@sure.ci', 'password' => 'secret123', 'is_active' => true]);
        $ro = $this->api($u->createToken('t')->plainTextToken);
        $row = $ro->getJson('/api/acquisition/records/sources/'.$src->id)->assertOk()->json('data');
        $this->assertArrayNotHasKey('token', $row);
        $this->assertNull($row['webhook_url']);
        $ro->getJson('/api/acquisition/lookup/partner')->assertForbidden();
        // réglages de la société : les clés non prévues (quotas, paie, clôture) sont ignorées
        $api()->putJson('/api/company', ['settings' => ['tax_rate' => 18, 'documents_quota_mb' => 999999, 'compta' => ['lock_date' => '2099-01-01']]])->assertOk();
        $s = (array) $company->fresh()->settings;
        $this->assertEquals(18, $s['tax_rate']);
        $this->assertArrayNotHasKey('documents_quota_mb', $s);
        $this->assertNotSame('2099-01-01', $s['compta']['lock_date'] ?? null);
        // lien dangereux refusé dans une fiche
        $api()->postJson('/api/acquisition/records/pages', ['title' => 'P', 'headline' => 'H', 'fields' => ['phone'], 'redirect_url' => 'javascript:alert(1)'])->assertStatus(422);
        $api()->getJson('/api/acquisition/report?from=0001-01-01&to=2026-01-01')->assertStatus(422);
    }

    public function test_whatsapp_webhook_ignores_foreign_formats_and_internal_media(): void
    {
        [$company, , $token] = $this->makeCompany('Boutique WA');
        $api = $this->api($token);
        $api->putJson('/api/messaging/channels/whatsapp', ['provider' => 'meta', 'settings' => ['phone_number_id' => '77', 'access_token' => 'tok']])->assertOk();
        $hook = app(\App\Modules\Whatsapp\Inbox::class)->settings($company)->webhook_token;
        Http::fake();
        $this->postJson('/api/whatsapp-webhook/'.$hook, ['event_type' => 'message_received', 'data' => ['from' => '22507@c.us', 'type' => 'image', 'media' => 'http://169.254.169.254/latest/meta-data/']])->assertOk();
        Http::assertNothingSent();
    }

    public function test_expense_of_colleague_cannot_be_taken_over(): void
    {
        [$company, , $token] = $this->makeCompany('Cabinet Sûr');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/sync/pull?cursor=0')->assertOk();
        $roleId = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Salarié')->value('id');
        $mk = fn (string $n) => User::create(['company_id' => $company->id, 'name' => $n, 'email' => strtolower($n).'@sur.ci', 'password' => 'secret123', 'role_id' => $roleId]);
        $kofi = $mk('Kofi');
        $awa = $mk('Awa');
        $eK = $this->uuid();
        $eA = $this->uuid();
        $push = fn ($client, array $m) => $client->postJson('/api/sync/push', ['mutations' => array_map(fn ($x) => $x + ['id' => $this->uuid(), 'op' => 'upsert'], $m)])->assertOk()->json('results');
        $push($api(), [
            ['entity' => 'rh_employees', 'record_id' => $eK, 'data' => ['first_name' => 'Kofi', 'last_name' => 'Y', 'base_salary' => 1, 'hire_date' => '2025-01-01', 'user_id' => $kofi->id]],
            ['entity' => 'rh_employees', 'record_id' => $eA, 'data' => ['first_name' => 'Awa', 'last_name' => 'K', 'base_salary' => 1, 'hire_date' => '2025-01-01', 'user_id' => $awa->id]],
        ]);
        $kApi = $this->api($kofi->createToken('t')->plainTextToken);
        $cat = DB::table('nf_categories')->where('company_id', $company->id)->value('id');
        $x = $this->uuid();
        $this->assertSame('applied', $push($kApi, [['entity' => 'nf_expenses', 'record_id' => $x, 'data' => ['employee_id' => $eK, 'category_id' => $cat, 'date' => now()->toDateString(), 'amount' => 1000, 'description' => 'Taxi']]])[0]['status']);
        $aApi = $this->api($awa->createToken('t')->plainTextToken);
        $r = $push($aApi, [['entity' => 'nf_expenses', 'record_id' => $x, 'data' => ['employee_id' => $eA, 'amount' => 900000]]])[0];
        $this->assertNotSame('applied', $r['status']);
        $this->assertSame($eK, DB::table('nf_expenses')->where('id', $x)->value('employee_id'));
    }
}
