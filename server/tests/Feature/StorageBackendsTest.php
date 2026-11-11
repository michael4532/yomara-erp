<?php

namespace Tests\Feature;

use App\Models\StorageBackend;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageBackendsTest extends TestCase
{
    public function test_files_go_to_the_next_storage_when_one_is_full_and_stay_readable(): void
    {
        Storage::fake('local');
        $bucket = [];
        Http::fake(function ($req) use (&$bucket) {
            $key = parse_url($req->url(), PHP_URL_PATH);
            return match ($req->method()) {
                'PUT' => ($bucket[$key] = $req->body()) !== null ? Http::response('', 200) : null,
                'GET' => isset($bucket[$key]) ? Http::response($bucket[$key], 200) : Http::response('<Error><Message>Not found</Message></Error>', 404),
                'DELETE' => (function () use (&$bucket, $key) { unset($bucket[$key]); return Http::response('', 204); })(),
            };
        });
        [, , $sa] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [, , $client] = $this->makeCompany('Client');
        $this->api($client)->getJson('/api/admin/storage')->assertForbidden();

        $list = $this->api($sa)->getJson('/api/admin/storage')->assertOk()->json();
        $local = $list['data'][0];
        $this->assertSame('local', $local['provider']);
        $this->assertArrayHasKey('r2', $list['providers']);

        // R2 ajouté et testé ; les clés ne sont jamais renvoyées
        $r = $this->api($sa)->postJson('/api/admin/storage', ['name' => 'R2 principal', 'provider' => 'r2', 'endpoint' => 'https://acc.r2.cloudflarestorage.com', 'region' => 'auto',
            'bucket' => 'yomara', 'access_key' => 'AK', 'secret_key' => 'SK', 'capacity_mb' => 10240])->assertOk()->json();
        $this->assertTrue($r['test']['ok']);
        $this->assertStringNotContainsString('SK', json_encode($r));
        $r2 = collect($r['data'])->firstWhere('provider', 'r2')['id'] ?? StorageBackend::where('provider', 'r2')->value('id');

        // Serveur plein (capacité 1 Mo) : le fichier part sur R2
        $this->api($sa)->putJson("/api/admin/storage/{$local['id']}", ['capacity_mb' => 1])->assertOk();
        StorageBackend::whereKey($local['id'])->update(['used_bytes' => 1048576]);
        $id = $this->api($sa)->post('/api/documents/files', ['file' => UploadedFile::fake()->createWithContent('Contrat.txt', 'Contrat de bail')])->assertCreated()->json('data.id');
        $this->assertSame($r2, \App\Modules\Documents\Models\DocVersion::where('file_id', $id)->value('backend_id'));
        $this->assertGreaterThan(0, StorageBackend::find($r2)->used_bytes);
        $this->assertSame('Contrat de bail', $this->api($sa)->get("/api/documents/files/$id/download")->assertOk()->streamedContent());

        // Un stockage qui contient des fichiers ne peut pas être supprimé ; ordre de priorité modifiable
        $this->api($sa)->deleteJson("/api/admin/storage/$r2")->assertStatus(422);
        $order = $this->api($sa)->postJson('/api/admin/storage/reorder', ['ids' => [$r2, $local['id']]])->assertOk()->json('data');
        $this->assertSame([$r2, $local['id']], array_column($order, 'id'));

        // Tous pleins : message clair
        StorageBackend::query()->update(['capacity_mb' => 1, 'used_bytes' => 1048576]);
        $this->api($sa)->post('/api/documents/files', ['file' => UploadedFile::fake()->createWithContent('B.txt', 'x')])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Tous les stockages de la plateforme sont pleins. Prévenez l\'éditeur.']);
    }
}
