<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Mises à jour à distance (diffusion par la plateforme, état côté installation) et « Ma journée » (tâches, agenda). */
class UpdatesTest extends TestCase
{
    public function test_publisher_releases_and_client_status(): void
    {
        config(['erp.updates.publisher' => true]);
        [$company, $admin, $token] = $this->makeCompany('Plateforme', 'entreprise', true);
        $api = fn () => $this->api($token);

        // Paquet minimal (racine yomara-erp/) envoyé en deux morceaux
        $zipPath = tempnam(sys_get_temp_dir(), 'rel').'.zip';
        $z = new \ZipArchive;
        $z->open($zipPath, \ZipArchive::CREATE);
        $z->addFromString('yomara-erp/config/erp.php', "<?php return ['version' => '9.9.1'];");
        $z->addFromString('yomara-erp/vendor/autoload.php', '<?php');
        $z->addFromString('yomara-erp/.env', 'SECRET=1');
        $z->close();
        $bytes = file_get_contents($zipPath);
        $half = intdiv(strlen($bytes), 2);
        $chunk = function (string $data) {
            $p = tempnam(sys_get_temp_dir(), 'ch');
            file_put_contents($p, $data);

            return new UploadedFile($p, 'chunk.bin', 'application/octet-stream', null, true);
        };
        $api()->post('/api/admin/releases/upload', ['upload_id' => 'abc123', 'index' => 0, 'total' => 2, 'chunk' => $chunk(substr($bytes, 0, $half))], ['Accept' => 'application/json'])
            ->assertOk()->assertJson(['received' => 1]);
        $rel = $api()->post('/api/admin/releases/upload', ['upload_id' => 'abc123', 'index' => 1, 'total' => 2, 'chunk' => $chunk(substr($bytes, $half)), 'notes' => 'Nouveautés', 'channel' => 'stable'],
            ['Accept' => 'application/json'])->assertOk()->json('release');
        $this->assertEquals(['9.9.1', false, hash('sha256', $bytes)], [$rel['version'], $rel['published'], $rel['sha256']]);

        // Pas encore publiée : rien à proposer
        $this->getJson('/api/updates/latest?version=0.65.0&install_id=aaaaaaaaaaaaaaaa1111')->assertOk()->assertJson(['latest' => null]);
        $api()->putJson("/api/admin/releases/{$rel['id']}", ['published' => true, 'mandatory' => true, 'apps' => ['windows' => 'https://dl.example/yomara.exe']])->assertOk()->assertJson(['published' => true]);
        $l = $this->getJson('/api/updates/latest?version=0.65.0&install_id=aaaaaaaaaaaaaaaa1111&name=Client+Test&php=8.3')->assertOk()->json('latest');
        $this->assertEquals(['9.9.1', true, 'Nouveautés', 'https://dl.example/yomara.exe'], [$l['version'], $l['mandatory'], $l['notes'], $l['apps']['windows']]);
        $this->get(parse_url($l['download'], PHP_URL_PATH))->assertOk();
        $this->get('/api/updates/download/'.$rel['id'].'/mauvaiseempreinte')->assertNotFound();
        $this->assertEquals(1, DB::table('upd_releases')->where('id', $rel['id'])->value('downloads'));
        $idx = $api()->getJson('/api/admin/releases')->assertOk()->json();
        $this->assertEquals(['Client Test', 1], [$idx['installations'][0]['name'], $idx['stats']['installations']]);
        // La plateforme refuse d'installer sur elle-même une version qui n'est pas plus récente
        $old = tempnam(sys_get_temp_dir(), 'old').'.zip';
        $z = new \ZipArchive;
        $z->open($old, \ZipArchive::CREATE);
        $z->addFromString('yomara-erp/config/erp.php', "<?php return ['version' => '0.0.1'];");
        $z->addFromString('yomara-erp/vendor/autoload.php', '<?php');
        $z->close();
        $oldRel = app(\App\Services\Updates\Publisher::class)->register($old, 'stable', null, false);
        $api()->postJson('/api/admin/releases/'.$oldRel['id'].'/install')->assertStatus(422);
        $api()->deleteJson('/api/admin/releases/'.$oldRel['id'])->assertNoContent();

        // Installation cliente : interroge le serveur de diffusion
        config(['erp.updates.publisher' => false, 'erp.updates.server' => 'https://maj.example']);
        Http::fake(['maj.example/*' => Http::response(['latest' => ['version' => '9.9.1', 'notes' => 'Nouveautés', 'size' => 1000, 'sha256' => 'x', 'php_min' => '8.2', 'mandatory' => false, 'download' => 'https://maj.example/d']])]);
        $s = $api()->getJson('/api/system/update/status?force=1')->assertOk()->json();
        $this->assertEquals([config('erp.version'), true, '9.9.1'], [$s['current'], $s['available'], $s['latest']['version']]);
        $pre = $api()->getJson('/api/system/update/preflight')->assertOk()->json();
        $this->assertContains('Version de PHP', array_column($pre['checks'], 'label'));
        Http::assertSent(fn ($req) => str_contains($req->url(), 'install_id='));
        // Un utilisateur sans droits d'administration ne peut pas mettre à jour
        $clerk = User::create(['company_id' => $company->id, 'name' => 'Vendeur', 'email' => 'v@x.ci', 'password' => 'secret123',
            'role_id' => \App\Models\Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id')]);
        $this->api($clerk->createToken('t')->plainTextToken)->postJson('/api/system/update/install')->assertForbidden();
        $api()->postJson('/api/system/update/finish')->assertStatus(422);
        config(['erp.updates.publisher' => true]);
        $api()->deleteJson("/api/admin/releases/{$rel['id']}")->assertNoContent();
        @unlink(storage_path('app/updates/latest.json'));
    }

    public function test_my_day_and_personal_todos(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Ma Journée SARL');
        $api = fn () => $this->api($token);
        $t = $api()->postJson('/api/me/todos', ['title' => 'Appeler la banque', 'important' => true])->assertCreated()->json('data');
        $api()->postJson('/api/me/todos', ['title' => 'Relancer Hôtel Ivoire', 'due_date' => now()->toDateString()])->assertCreated();
        $this->assertSame(2, $api()->getJson('/api/me/todos')->json('open'));
        $api()->putJson("/api/me/todos/{$t['id']}", ['done' => true])->assertOk()->assertJson(['data' => ['done' => true]]);
        $list = $api()->getJson('/api/me/todos')->assertOk()->json();
        $this->assertEquals([1, 'Relancer Hôtel Ivoire', true], [$list['open'], $list['data'][0]['title'], $list['data'][1]['done']]);
        // Les tâches d'un autre utilisateur sont invisibles
        [, , $other] = $this->makeCompany('Autre');
        $this->api($other)->putJson("/api/me/todos/{$t['id']}", ['done' => false])->assertNotFound();

        $day = $api()->getJson('/api/me/day')->assertOk()->json();
        $this->assertEquals([now()->toDateString(), 1], [$day['date'], $day['todos_open']]);
        $this->assertArrayHasKey('month_days', $day);
        $api()->deleteJson("/api/me/todos/{$t['id']}")->assertNoContent();
    }
}
