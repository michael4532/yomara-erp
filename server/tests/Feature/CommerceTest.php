<?php

namespace Tests\Feature;

use Tests\TestCase;

class CommerceTest extends TestCase
{
    protected string $token;

    protected string $productId;

    protected string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        [, , $this->token] = $this->makeCompany('Boutique');

        $this->productId = $this->api($this->token)->postJson('/api/core/products', [
            'name' => 'Sac de riz 25kg', 'sale_price' => 15000, 'purchase_price' => 11000, 'tax_rate' => 18, 'min_stock' => 5,
        ])->assertCreated()->json('data.id');
        $this->customerId = $this->api($this->token)->postJson('/api/core/partners', ['name' => 'Client Koné', 'type' => 'customer'])->json('data.id');

        // Stock initial : 100 sacs
        $wh = $this->api($this->token)->postJson('/api/stock/warehouses', ['name' => 'Magasin Yopougon', 'is_default' => true])->assertCreated()->json('data.id');
        $this->api($this->token)->postJson('/api/stock/moves', [
            'product_id' => $this->productId, 'warehouse_id' => $wh, 'quantity' => 100, 'type' => 'initial', 'date' => now()->toDateString(),
        ])->assertCreated();
    }

    protected function stockOf(string $productId): float
    {
        return collect($this->api($this->token)->getJson('/api/stock/levels')->json('data'))->where('product_id', $productId)->sum('quantity');
    }

    public function test_invoice_created_offline_validated_on_sync_with_number_stock_and_payments(): void
    {
        $docId = $this->uuid();
        $res = $this->api($this->token)->postJson('/api/sync/push', ['mutations' => [[
            'id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $docId,
            'data' => [
                'type' => 'invoice', 'partner_id' => $this->customerId, 'date' => now()->toDateString(), 'status' => 'validated',
                'lines' => [
                    ['id' => $this->uuid(), 'product_id' => $this->productId, 'description' => 'Sac de riz 25kg', 'quantity' => 10, 'unit_price' => 15000, 'tax_rate' => 18, 'discount_percent' => 10],
                    ['description' => 'Livraison', 'quantity' => 1, 'unit_price' => 5000, 'tax_rate' => 0],
                ],
            ],
        ]]])->assertOk()->json('results.0');

        $this->assertSame('applied', $res['status'], json_encode($res));
        $doc = $res['record'];
        $this->assertSame('validated', $doc['status']);
        $this->assertSame('FAC-'.now()->year.'-00001', $doc['number']);
        $this->assertSame('Client Koné', $doc['partner_name']);
        // 10 × 15000 = 150000, −10 % = 135000, TVA 18 % = 24300 → 159300 ; + livraison 5000
        $this->assertEquals(140000, $doc['subtotal']);
        $this->assertEquals(24300, $doc['tax_total']);
        $this->assertEquals(164300, $doc['total']);
        $this->assertEquals(15000, $doc['discount_total']);
        $this->assertNotNull($doc['due_date']);
        $this->assertCount(2, $doc['lines']);

        $this->assertEquals(90, $this->stockOf($this->productId));

        // Une facture validée n'est plus modifiable
        $this->api($this->token)->putJson("/api/ventes/documents/$docId", ['lines' => []])->assertStatus(422);

        // Paiement partiel Mobile Money puis solde
        $this->api($this->token)->postJson('/api/ventes/payments', ['document_id' => $docId, 'date' => now()->toDateString(), 'amount' => 100000, 'method' => 'mobile_money'])->assertCreated();
        $this->assertSame('partial', $this->api($this->token)->getJson("/api/ventes/documents/$docId")->json('data.payment_status'));
        $this->api($this->token)->postJson('/api/ventes/payments', ['document_id' => $docId, 'date' => now()->toDateString(), 'amount' => 70000, 'method' => 'cash'])->assertStatus(422); // dépasse
        $this->api($this->token)->postJson('/api/ventes/payments', ['document_id' => $docId, 'date' => now()->toDateString(), 'amount' => 64300, 'method' => 'cash'])->assertCreated();
        $d = $this->api($this->token)->getJson("/api/ventes/documents/$docId")->json('data');
        $this->assertSame('paid', $d['payment_status']);
        $this->assertEquals(0, $d['balance']);

        // Impossible d'annuler une facture payée
        $this->api($this->token)->postJson("/api/ventes/documents/$docId/cancel")->assertStatus(422);

        // Tableau de bord
        $widgets = collect($this->api($this->token)->getJson('/api/dashboard')->json('data'))->keyBy('key');
        $this->assertEquals(164300, $widgets['revenue_month']['value']);
        $this->assertEquals(0, $widgets['unpaid']['value']);
    }

    public function test_quote_converted_to_invoice_and_cancel_restores_stock(): void
    {
        $quote = $this->api($this->token)->postJson('/api/ventes/documents', [
            'type' => 'quote', 'partner_id' => $this->customerId, 'date' => now()->toDateString(),
            'lines' => [['product_id' => $this->productId, 'description' => 'Riz', 'quantity' => 4, 'unit_price' => 15000, 'tax_rate' => 18]],
        ])->assertCreated()->json('data');
        $this->assertSame('draft', $quote['status']);
        $this->assertNull($quote['number']);

        $this->api($this->token)->postJson("/api/ventes/documents/{$quote['id']}/validate")->assertOk()->assertJsonPath('data.number', 'DEV-'.now()->year.'-00001');
        $this->assertEquals(100, $this->stockOf($this->productId)); // un devis ne touche pas le stock

        $inv = $this->api($this->token)->postJson("/api/ventes/documents/{$quote['id']}/convert", ['type' => 'invoice'])->assertCreated()->json('data');
        $this->assertSame($quote['id'], $inv['source_id']);
        $this->assertEquals(70800, $inv['total']);

        $this->api($this->token)->postJson("/api/ventes/documents/{$inv['id']}/validate")->assertOk();
        $this->assertEquals(96, $this->stockOf($this->productId));

        $this->api($this->token)->postJson("/api/ventes/documents/{$inv['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertEquals(100, $this->stockOf($this->productId));
    }

    public function test_supplier_invoice_adds_stock_and_draft_can_be_deleted(): void
    {
        $supplier = $this->api($this->token)->postJson('/api/core/partners', ['name' => 'Grossiste SA', 'type' => 'supplier'])->json('data.id');
        $doc = $this->api($this->token)->postJson('/api/achats/documents', [
            'type' => 'supplier_invoice', 'partner_id' => $supplier, 'date' => now()->toDateString(),
            'lines' => [['product_id' => $this->productId, 'description' => 'Riz', 'quantity' => 50, 'unit_price' => 11000, 'tax_rate' => 18]],
        ])->assertCreated()->json('data');
        $this->api($this->token)->postJson("/api/achats/documents/{$doc['id']}/validate")->assertOk()->assertJsonPath('data.number', 'FF-'.now()->year.'-00001');
        $this->assertEquals(150, $this->stockOf($this->productId));

        // Les achats n'apparaissent pas dans les ventes
        $this->assertSame(0, $this->api($this->token)->getJson('/api/ventes/documents')->json('total'));

        $draft = $this->api($this->token)->postJson('/api/ventes/documents', ['type' => 'quote', 'date' => now()->toDateString()])->json('data.id');
        $this->api($this->token)->deleteJson("/api/ventes/documents/$draft")->assertOk();
    }

    public function test_stock_moves_are_append_only_and_inventory_adjusts(): void
    {
        $moves = $this->api($this->token)->getJson('/api/stock/moves')->json('data');
        $this->api($this->token)->deleteJson('/api/stock/moves/'.$moves[0]['id'])->assertStatus(422);

        $wh = $moves[0]['warehouse_id'];
        $this->api($this->token)->postJson('/api/stock/inventory', ['warehouse_id' => $wh, 'counts' => [['product_id' => $this->productId, 'quantity' => 97]]])->assertOk();
        $this->assertEquals(97, $this->stockOf($this->productId));
    }
}
