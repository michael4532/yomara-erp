<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Sauvegardes : archive chiffrée (base + fichiers), restauration, clé, Google Drive et Dropbox. */
class BackupTest extends TestCase
{
    protected string $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = sys_get_temp_dir().'/yomara-backup-test-'.uniqid();
        File::ensureDirectoryExists($this->store.'/app/private/documents');
        File::ensureDirectoryExists($this->store.'/framework/cache');
        $this->app->useStoragePath($this->store);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        parent::tearDown();
    }

    public function test_backup_restore_and_cloud(): void
    {
        [$company, $user, $token] = $this->makeCompany('Sauvegarde SARL', superAdmin: true); // propriétaire du logiciel (compte d'installation)
        $api = fn () => $this->api($token);
        $keep = $api()->postJson('/api/core/products', ['name' => 'Produit gardé', 'sale_price' => 1000])->json('data.id');
        file_put_contents($this->store.'/app/private/documents/contrat.pdf', '%PDF contrat');

        $idx = $api()->getJson('/api/system/backups')->assertOk()->json();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}(-[A-Z0-9]{6}){3}$/', $idx['passphrase']);
        $this->assertTrue($idx['encrypted']);
        $b = $api()->postJson('/api/system/backups/run')->assertOk()->json('data');
        $this->assertStringStartsWith('yomara-sauvegarde-', $b['name']);
        $this->assertGreaterThan(0, $b['rows']);
        $this->assertSame(1, $b['files']);
        // Archive chiffrée : illisible sans la clé, manifeste lisible
        $zip = new \ZipArchive;
        $zip->open($this->store.'/app/backups/'.$b['name']);
        $this->assertFalse(@$zip->getFromName('db/products.jsonl'));
        $this->assertSame('Yomara ERP', json_decode($zip->getFromName('manifest.json'), true)['app']);
        $zip->close();
        $this->assertSame('ok', $api()->getJson('/api/system/backups')->json('last.status'));
        $url = $api()->getJson('/api/system/backups/files/'.$b['name'].'/link')->assertOk()->json('url');
        $this->flushHeaders();
        $this->get(str_replace(url('/'), '', $url))->assertOk();
        $this->get('/sauvegardes/fichier/'.$b['name'])->assertForbidden(); // lien non signé

        // Changements après la sauvegarde, puis restauration
        $api = fn () => $this->api($token);
        $api()->deleteJson("/api/core/products/$keep")->assertSuccessful();
        $api()->postJson('/api/core/products', ['name' => 'Produit en trop', 'sale_price' => 1])->assertCreated();
        unlink($this->store.'/app/private/documents/contrat.pdf');
        $api()->postJson('/api/system/backups/restore', ['name' => $b['name'], 'passphrase' => 'MAUVAISE-CLE-0000', 'confirm' => true])->assertStatus(422)->assertJsonPath('message', 'Clé de sauvegarde incorrecte.');
        $api()->postJson('/api/system/backups/restore', ['name' => $b['name']])->assertStatus(422); // confirmation exigée
        $r = $api()->postJson('/api/system/backups/restore', ['name' => $b['name'], 'confirm' => true])->assertOk()->json('data');
        $this->assertSame(1, $r['files']);
        $this->assertNotNull(DB::table('products')->where('id', $keep)->whereNull('deleted_at')->first());
        $this->assertNull(DB::table('products')->where('name', 'Produit en trop')->first());
        $this->assertSame('%PDF contrat', file_get_contents($this->store.'/app/private/documents/contrat.pdf'));
        $this->assertStringStartsWith('yomara-sauvegarde-', $r['safety']); // état d'avant gardé
        $list = $this->api($token)->getJson('/api/system/backups')->assertOk()->json();
        $this->assertCount(2, $list['backups']);
        $this->assertSame($idx['passphrase'], $list['passphrase']); // réglages des sauvegardes conservés

        // Google Drive : connexion, envoi chaque nuit, rétention
        config(['erp.mailbox.google_client_id' => 'gid', 'erp.mailbox.google_client_secret' => 'gsec', 'erp.backups.dropbox_key' => 'dk', 'erp.backups.dropbox_secret' => 'ds']);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'GAT', 'refresh_token' => 'GRT', 'expires_in' => 3600]),
            'openidconnect.googleapis.com/*' => Http::response(['email' => 'patron@gmail.com']),
            'www.googleapis.com/drive/v3/files?*' => fn ($r) => str_contains(urldecode($r->url()), 'google-apps.folder') ? Http::response(['files' => []]) : Http::response(['files' => [['id' => 'F3', 'name' => 'c.zip', 'size' => '10', 'createdTime' => '2026-10-30T02:00:00Z'], ['id' => 'F2', 'name' => 'b.zip', 'createdTime' => '2026-10-29T02:00:00Z'], ['id' => 'F1', 'name' => 'a.zip', 'createdTime' => '2026-10-28T02:00:00Z']]]),
            'www.googleapis.com/drive/v3/files' => Http::response(['id' => 'FOLDER1']),
            'www.googleapis.com/drive/v3/files/*' => Http::response([], 204),
            'www.googleapis.com/upload/drive/v3/files?uploadType=resumable' => Http::response([], 200, ['Location' => 'https://www.googleapis.com/upload/session/XYZ']),
            'www.googleapis.com/upload/session/XYZ' => Http::response(['id' => 'NEW']),
            'api.dropboxapi.com/oauth2/token' => Http::response(['access_token' => 'DAT', 'refresh_token' => 'DRT', 'expires_in' => 14400]),
            'api.dropboxapi.com/2/users/get_current_account' => Http::response(['email' => 'patron@dropbox.com']),
            'content.dropboxapi.com/2/files/upload' => Http::response(['id' => 'id:1']),
            'api.dropboxapi.com/2/files/list_folder' => Http::response(['entries' => []]),
        ]);
        $api = fn () => $this->api($token);
        $api()->putJson('/api/system/backups', ['keep_remote' => 2])->assertOk();
        $u = $api()->postJson('/api/system/backups/connect/google')->assertOk()->json('url');
        $this->assertStringContainsString('drive.file', urldecode($u));
        parse_str(parse_url($u, PHP_URL_QUERY), $q);
        $this->flushHeaders();
        $this->get('/oauth/sauvegarde/google/retour?state=nope&code=C')->assertSee('expiré');
        $u = $this->api($token)->postJson('/api/system/backups/connect/google')->json('url');
        parse_str(parse_url($u, PHP_URL_QUERY), $q);
        $this->flushHeaders();
        $this->get('/oauth/sauvegarde/google/retour?state='.$q['state'].'&code=C')->assertOk()->assertSee('patron@gmail.com');
        $u = $this->api($token)->postJson('/api/system/backups/connect/dropbox')->json('url');
        parse_str(parse_url($u, PHP_URL_QUERY), $q);
        $this->flushHeaders();
        $this->get('/oauth/sauvegarde/dropbox/retour?state='.$q['state'].'&code=D')->assertOk()->assertSee('Dropbox connecté');

        $b2 = $this->api($token)->postJson('/api/system/backups/run')->assertOk()->json('data');
        $this->assertEquals(['ok', 'ok'], [$b2['uploads']['google']['status'], $b2['uploads']['dropbox']['status']]);
        Http::assertSent(fn ($r) => $r->url() === 'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable' && $r['parents'] === ['FOLDER1'] && $r['name'] === $b2['name']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->url() === 'https://www.googleapis.com/upload/session/XYZ');
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/files/F1')); // rétention : 2 copies
        Http::assertSent(fn ($r) => $r->url() === 'https://content.dropboxapi.com/2/files/upload' && json_decode($r->header('Dropbox-API-Arg')[0], true)['path'] === '/Sauvegardes Yomara ERP/'.$b2['name']);
        $st = $this->api($token)->getJson('/api/system/backups')->json();
        $this->assertEquals([true, 'patron@gmail.com', true], [$st['destinations']['google']['connected'], $st['destinations']['google']['email'], $st['destinations']['dropbox']['connected']]);
        $this->assertCount(3, $this->api($token)->getJson('/api/system/backups/remote/google')->assertOk()->json('data'));

        // Planification : pas avant l'heure réglée, une seule fois par jour
        $svc = app(\App\Backups\BackupService::class);
        $svc->save(['hour' => 0, 'last' => ['at' => now()->toIso8601String()]]);
        $this->assertNull($svc->runDue());
        $svc->save(['last' => ['at' => now()->subDay()->toIso8601String()]]);
        $this->assertNotNull($svc->runDue());
    }

    public function test_reserved_to_admin(): void
    {
        [$company, $user, $token] = $this->makeCompany('Autre SARL');
        $u = \App\Models\User::create(['company_id' => $company->id, 'name' => 'Caissier', 'email' => 'caisse@autre.ci', 'password' => 'secret123',
            'role_id' => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Caissier')->value('id')]);
        $this->api($u->createToken('c')->plainTextToken)->getJson('/api/system/backups')->assertForbidden();
    }
}
