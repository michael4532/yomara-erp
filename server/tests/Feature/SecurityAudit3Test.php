<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Audit du 6 novembre 2026 (serveur) : chaque test rejoue l'attaque et vérifie qu'elle ne passe plus. */
class SecurityAudit3Test extends TestCase
{
    public function test_sync_push_cannot_overwrite_a_record_id_of_another_company(): void
    {
        [, , $tokenA] = $this->makeCompany('Société A');
        $id = $this->uuid();
        $this->api($tokenA)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'partners', 'record_id' => $id,
            'data' => ['name' => 'Client secret A', 'type' => 'customer']]]])->assertOk()->assertJsonPath('results.0.status', 'applied');

        [, , $tokenB] = $this->makeCompany('Société B');
        $r = $this->api($tokenB)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'partners', 'record_id' => $id,
            'data' => ['name' => 'Pirate', 'type' => 'customer']]]])->assertOk()->json('results.0');
        $this->assertSame('rejected', $r['status']);
        $this->assertNull($r['record']);
        $this->assertSame('Client secret A', \Illuminate\Support\Facades\DB::table('partners')->where('id', $id)->value('name'));
    }

    public function test_payment_gateway_cannot_point_to_another_company_treasury_account(): void
    {
        [$a] = $this->makeCompany('Trésor A');
        $foreign = $this->uuid();
        \Illuminate\Support\Facades\DB::table('tr_accounts')->insert(['id' => $foreign, 'company_id' => $a->id, 'name' => 'Banque A',
            'created_at' => now(), 'updated_at' => now()]);
        [, , $token] = $this->makeCompany('Trésor B');
        $this->api($token)->postJson('/api/payments/gateways', ['driver' => 'merchant_link', 'label' => 'Wave', 'credentials' => ['url' => 'https://pay.wave.com/m/M_1'],
            'treasury_account_id' => $foreign])->assertStatus(422)->assertJsonValidationErrors('treasury_account_id');
    }

    public function test_remote_update_cannot_skip_signature_with_a_local_path(): void
    {
        [, , $token] = $this->makeCompany('Install', 'entreprise', true);
        $pkg = tempnam(sys_get_temp_dir(), 'evil').'.zip';
        $z = new \ZipArchive;
        $z->open($pkg, \ZipArchive::CREATE);
        $z->addFromString('yomara-erp/config/erp.php', "<?php return ['version' => '99.0.0'];");
        $z->addFromString('yomara-erp/vendor/autoload.php', '<?php');
        $z->addFromString('yomara-erp/routes/pwned.php', '<?php // code non signé');
        $z->close();
        config(['erp.updates.publisher' => false, 'erp.updates.server' => 'https://maj.example']);
        \Illuminate\Support\Facades\Http::fake(['maj.example/d' => \Illuminate\Support\Facades\Http::response(file_get_contents($pkg)), 'maj.example/*' => \Illuminate\Support\Facades\Http::response(['latest' => [
            'version' => '99.0.0', 'size' => filesize($pkg), 'sha256' => hash_file('sha256', $pkg), 'php_min' => '8.2', 'path' => $pkg, 'download' => 'https://maj.example/d']])]);
        try {
            $this->api($token)->postJson('/api/system/update/install')->assertStatus(422)->assertJsonFragment(['message' => 'Mise à jour refusée : le paquet n\'est pas signé par Yomara Technologies.']);
            $this->assertFileDoesNotExist(base_path('routes/pwned.php'));

            // Version servant de nom de fichier : pas de remontée de dossier
            \Illuminate\Support\Facades\Http::fake(['maj.example/*' => \Illuminate\Support\Facades\Http::response(['latest' => [
                'version' => '99.0.0/../../../x', 'sha256' => 'x', 'download' => 'https://maj.example/d']])]);
            $this->api($token)->postJson('/api/system/update/install')->assertStatus(422);
        } finally {
            @unlink($pkg);
            @unlink(base_path('routes/pwned.php'));
            @unlink(storage_path('app/updates/latest.json'));
            @unlink(storage_path('app/updates/lock'));
        }
    }
    public function test_super_admin_api_key_stays_in_its_own_company(): void
    {
        [$platform, $admin, $token] = $this->makeCompany('Éditeur Clés', 'entreprise', true);
        [$client] = $this->makeCompany('Client Clés');
        \Illuminate\Support\Facades\DB::table('partners')->insert(['id' => $this->uuid(), 'company_id' => $client->id, 'name' => 'Contact privé du client', 'type' => 'customer',
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $key = $this->api($token)->postJson('/api/integrations/keys', ['name' => 'Zapier', 'write' => false])->assertCreated()->json('token');
        // la session normale du super admin peut toujours agir dans une société cliente
        $this->assertStringContainsString('Contact priv', $this->api($token)->withHeaders(['X-Company-Id' => (string) $client->id])->getJson('/api/core/partners')->getContent());
        // la clé d'API, non : elle reste dans la société du créateur
        $res = $this->api($key)->withHeaders(['X-Company-Id' => (string) $client->id])->getJson('/api/core/partners')->assertOk();
        $this->assertStringNotContainsString('Contact priv', $res->getContent());
    }

    public function test_malformed_query_parameters_do_not_cause_server_errors(): void
    {
        [, , $token] = $this->makeCompany('Paramètres');
        $api = $this->api($token);
        foreach (['/api/core/partners?sort[]=name', '/api/core/partners?q[]=x', '/api/me/day?date=pas-une-date', '/api/me/day?month=13-2026',
            '/api/veille/records/competitors?q[]=x', '/api/veille/records/competitors?kind[]=direct', '/api/veille/records/competitors?from[]=2026-01-01'] as $url) {
            $this->assertLessThan(500, $api->getJson($url)->status(), $url);
        }
        $this->assertCount(1, $api->getJson('/api/sync/pull?limit=-5')->assertOk()->json('changes.partners.upserts') ?? [1]);
    }
}
