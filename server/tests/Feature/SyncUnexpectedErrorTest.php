<?php

namespace Tests\Feature;

use App\Services\EntityWriter;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SyncUnexpectedErrorTest extends TestCase
{
    public function test_unexpected_error_rejects_one_record_without_blocking_the_queue(): void
    {
        [, , $token] = $this->makeCompany('Akwaba', 'entreprise', true);
        $real = app(EntityWriter::class);
        $this->app->instance(EntityWriter::class, new class($real) extends EntityWriter
        {
            public function __construct(private EntityWriter $inner) {}

            public function upsert(string $class, ?string $id, array $data, ?int $baseVersion = null): array
            {
                if (($data['partner_name'] ?? '') === 'Boum') {
                    throw new \RuntimeException('colonne inconnue');
                }

                return $this->inner->upsert($class, $id, $data, $baseVersion);
            }
        });

        $res = $this->api($token)->postJson('/api/sync/push', ['mutations' => [
            ['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['type' => 'invoice', 'date' => '2026-11-20', 'partner_name' => 'Boum']],
            ['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['type' => 'invoice', 'date' => '2026-11-20', 'partner_name' => 'NSI']],
        ]])->assertOk();

        $this->assertSame('rejected', $res->json('results.0.status'));
        $this->assertStringContainsString('colonne inconnue', $res->json('results.0.message'));
        $this->assertSame('applied', $res->json('results.1.status'));
    }

    public function test_super_admin_reads_recent_errors(): void
    {
        [, , $token] = $this->makeCompany('Akwaba', 'entreprise', true);
        $log = storage_path('logs/zz-test-errors.log');
        file_put_contents($log, "[2026-11-20 21:00:00] production.INFO: rien\n[2026-11-20 21:01:00] production.ERROR: SQLSTATE[42S22] colonne {\"exception\":\"x\"}\n#0 trace\n");
        touch($log, time() + 60);
        try {
            $rows = $this->api($token)->getJson('/api/admin/system/errors')->assertOk()->json('data');
            $this->assertCount(1, $rows);
            $this->assertStringContainsString('SQLSTATE[42S22]', $rows[0]['message']);
        } finally {
            @unlink($log);
        }
    }
}
