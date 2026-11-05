<?php

namespace Tests\Feature;

use Tests\TestCase;

class SyncTest extends TestCase
{
    public function test_offline_push_then_pull_is_idempotent_and_detects_conflicts(): void
    {
        [, , $token] = $this->makeCompany('A');
        $pid = $this->uuid();
        $mid = $this->uuid();

        $push = fn ($mutations) => $this->api($token)->postJson('/api/sync/push', ['mutations' => $mutations])->assertOk()->json('results');

        // 1) Création hors ligne (UUID généré par l'appareil)
        $r = $push([['id' => $mid, 'entity' => 'products', 'op' => 'upsert', 'record_id' => $pid, 'data' => ['name' => 'Huile 5L', 'sale_price' => 9000]]]);
        $this->assertSame('applied', $r[0]['status']);
        $this->assertSame(1, $r[0]['record']['version']);

        // 2) Renvoi de la même mutation (coupure réseau) → pas de doublon
        $r = $push([['id' => $mid, 'entity' => 'products', 'op' => 'upsert', 'record_id' => $pid, 'data' => ['name' => 'Huile 5L', 'sale_price' => 9000]]]);
        $this->assertSame('applied', $r[0]['status']);
        $this->assertSame(1, $this->api($token)->getJson('/api/core/products')->json('total'));

        // 3) Pull depuis 0 → l'appareil B reçoit le produit
        $pull = $this->api($token)->getJson('/api/sync/pull?cursor=0')->assertOk();
        $this->assertSame('Huile 5L', $pull->json('changes.products.upserts.0.name'));
        $cursor = $pull->json('cursor');

        // 4) Appareil A modifie (version 1 → 2)
        $r = $push([['id' => $this->uuid(), 'entity' => 'products', 'op' => 'upsert', 'record_id' => $pid, 'base_version' => 1, 'data' => ['sale_price' => 9500]]]);
        $this->assertSame('applied', $r[0]['status']);

        // 5) Appareil B, encore sur la version 1, modifie → conflit, version serveur renvoyée
        $r = $push([['id' => $this->uuid(), 'entity' => 'products', 'op' => 'upsert', 'record_id' => $pid, 'base_version' => 1, 'data' => ['sale_price' => 8000]]]);
        $this->assertSame('conflict', $r[0]['status']);
        $this->assertEquals(9500, $r[0]['record']['sale_price']);

        // 6) Pull incrémental
        $pull = $this->api($token)->getJson("/api/sync/pull?cursor=$cursor")->assertOk();
        $this->assertEquals(9500, $pull->json('changes.products.upserts.0.sale_price'));

        // 7) Suppression propagée
        $push([['id' => $this->uuid(), 'entity' => 'products', 'op' => 'delete', 'record_id' => $pid]]);
        $pull = $this->api($token)->getJson('/api/sync/pull?cursor='.$pull->json('cursor'));
        $this->assertSame([$pid], $pull->json('changes.products.deletes'));
    }

    public function test_invalid_and_forbidden_mutations_are_rejected(): void
    {
        [$c, , $token] = $this->makeCompany('A', 'starter'); // pas de module achats en SaaS… mais mode single : tout actif
        $r = $this->api($token)->postJson('/api/sync/push', ['mutations' => [
            ['id' => $this->uuid(), 'entity' => 'products', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['sale_price' => 5]], // nom manquant
            ['id' => $this->uuid(), 'entity' => 'inexistante', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => []],
        ]])->assertOk()->json('results');

        $this->assertSame('rejected', $r[0]['status']);
        $this->assertArrayHasKey('name', $r[0]['errors']);
        $this->assertSame('rejected', $r[1]['status']);
    }

    public function test_pagination_of_pull(): void
    {
        [, , $token] = $this->makeCompany('A');
        $mutations = [];
        for ($i = 0; $i < 25; $i++) {
            $mutations[] = ['id' => $this->uuid(), 'entity' => 'partners', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['name' => "Client $i"]];
        }
        $this->api($token)->postJson('/api/sync/push', ['mutations' => $mutations])->assertOk();

        $cursor = 0;
        $seen = 0;
        do {
            $p = $this->api($token)->getJson("/api/sync/pull?cursor=$cursor&limit=10")->json();
            $seen += count($p['changes']['partners']['upserts'] ?? []);
            $cursor = $p['cursor'];
        } while ($p['has_more']);

        $this->assertSame(25, $seen);
    }
}
