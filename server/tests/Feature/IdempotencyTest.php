<?php

namespace Tests\Feature;

use App\Modules\Core\Models\Partner;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    public function test_replayed_write_with_same_key_is_not_duplicated(): void
    {
        [, , $token] = $this->makeCompany('Idem SARL');
        $this->api($token)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $send = fn (string $key) => $this->api($token)->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/core/partners', ['name' => 'Client rejoué', 'type' => 'customer']);

        $a = $send('local-1')->assertSuccessful()->json('data.id');
        $b = $send('local-1')->assertSuccessful()->assertHeader('Idempotent-Replay', 'true')->json('data.id');
        $this->assertSame($a, $b);
        $this->assertSame(1, Partner::withoutGlobalScopes()->where('name', 'Client rejoué')->count());

        $send('local-2')->assertSuccessful();
        $this->assertSame(2, Partner::withoutGlobalScopes()->where('name', 'Client rejoué')->count());
    }
}
