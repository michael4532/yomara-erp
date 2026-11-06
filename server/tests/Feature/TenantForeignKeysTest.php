<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Cloisonnement des clés étrangères : une société ne peut jamais référencer un enregistrement d'une autre. */
class TenantForeignKeysTest extends TestCase
{
    public function test_company_b_cannot_point_a_sales_document_at_company_a_partner_or_warehouse(): void
    {
        [, , $tokenA] = $this->makeCompany('Alpha');
        [, , $tokenB] = $this->makeCompany('Beta');

        // Société A : un client et un entrepôt
        $partnerA = $this->api($tokenA)->postJson('/api/core/partners', ['name' => 'Client secret A', 'type' => 'customer'])->assertCreated()->json('data.id');
        $warehouseA = $this->api($tokenA)->postJson('/api/stock/warehouses', ['name' => 'Dépôt A'])->assertCreated()->json('data.id');
        $productA = $this->api($tokenA)->postJson('/api/core/products', ['name' => 'Article A', 'sale_price' => 1000])->assertCreated()->json('data.id');

        $line = ['description' => 'Prestation', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 0];
        $base = ['type' => 'invoice', 'date' => now()->toDateString(), 'lines' => [$line]];

        // API REST : refus explicite (422) sur chaque identifiant étranger
        $this->api($tokenB)->postJson('/api/ventes/documents', $base + ['partner_id' => $partnerA])
            ->assertStatus(422)->assertJsonValidationErrors('partner_id');
        $this->api($tokenB)->postJson('/api/ventes/documents', $base + ['warehouse_id' => $warehouseA])
            ->assertStatus(422)->assertJsonValidationErrors('warehouse_id');
        $this->api($tokenB)->postJson('/api/ventes/documents', ['type' => 'invoice', 'date' => now()->toDateString(), 'lines' => [$line + ['product_id' => $productA]]])
            ->assertStatus(422);

        // Synchronisation hors ligne : mutation rejetée, rien n'est créé
        $r = $this->api($tokenB)->postJson('/api/sync/push', ['mutations' => [
            ['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => $base + ['partner_id' => $partnerA]],
            ['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => $base + ['warehouse_id' => $warehouseA]],
        ]])->assertOk()->json('results');
        $this->assertSame('rejected', $r[0]['status']);
        $this->assertArrayHasKey('partner_id', $r[0]['errors']);
        $this->assertSame('rejected', $r[1]['status']);
        $this->assertArrayHasKey('warehouse_id', $r[1]['errors']);
        $this->assertSame(0, $this->api($tokenB)->getJson('/api/ventes/documents')->json('total'));

        // Ses propres client et entrepôt restent acceptés
        $partnerB = $this->api($tokenB)->postJson('/api/core/partners', ['name' => 'Client B', 'type' => 'customer'])->assertCreated()->json('data.id');
        $warehouseB = $this->api($tokenB)->postJson('/api/stock/warehouses', ['name' => 'Dépôt B'])->assertCreated()->json('data.id');
        $doc = $this->api($tokenB)->postJson('/api/ventes/documents', $base + ['partner_id' => $partnerB, 'warehouse_id' => $warehouseB])->assertCreated()->json('data');
        $this->assertSame('Client B', $doc['partner_name']);

        // A ne voit toujours rien de B
        $this->assertSame(0, $this->api($tokenA)->getJson('/api/ventes/documents')->json('total'));
    }
}
