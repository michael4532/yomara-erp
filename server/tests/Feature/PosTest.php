<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class PosTest extends TestCase
{
    protected string $admin;

    protected string $cashier;

    protected string $productId;

    protected string $registerId;

    protected string $warehouseId;

    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        [$company, , $this->admin] = $this->makeCompany('Supérette');
        $this->companyId = $company->id;

        // Caissier avec le rôle par défaut « Caissier »
        $roles = collect($this->api($this->admin)->getJson('/api/roles')->json('data'))->keyBy('name');
        $this->api($this->admin)->postJson('/api/users', [
            'name' => 'Awa', 'email' => 'awa@superette.ci', 'password' => 'caisse123', 'role_id' => $roles['Caissier']['id'],
        ])->assertCreated();
        $this->cashier = User::where('email', 'awa@superette.ci')->first()->createToken('caisse')->plainTextToken;

        $this->productId = $this->api($this->admin)->postJson('/api/core/products', ['name' => 'Coca-Cola 33cl', 'sale_price' => 423.73, 'tax_rate' => 18, 'purchase_price' => 300])->json('data.id');
        $this->warehouseId = $this->api($this->admin)->postJson('/api/stock/warehouses', ['name' => 'Boutique', 'is_default' => true])->json('data.id');
        $this->api($this->admin)->postJson('/api/stock/moves', ['product_id' => $this->productId, 'warehouse_id' => $this->warehouseId, 'quantity' => 48, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();
        $this->registerId = $this->api($this->admin)->postJson('/api/pos/registers', ['name' => 'Caisse 1', 'code' => 'C1', 'warehouse_id' => $this->warehouseId])->assertCreated()->json('data.id');
    }

    protected function push(string $token, array $mutations): array
    {
        return $this->api($token)->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid()], $mutations)])
            ->assertOk()->json('results');
    }

    protected function ticket(string $sessionId, int $qty, array $payments, string $ref): array
    {
        $doc = $this->uuid();
        $muts = [[
            'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $doc,
            'data' => [
                'type' => 'receipt', 'date' => now()->toDateString(), 'status' => 'validated', 'reference' => $ref,
                'pos_session_id' => $sessionId, 'warehouse_id' => $this->warehouseId,
                'lines' => [['product_id' => $this->productId, 'description' => 'Coca-Cola 33cl', 'quantity' => $qty, 'unit_price' => 423.73, 'tax_rate' => 18]],
            ],
        ]];
        foreach ($payments as [$method, $amount]) {
            $muts[] = ['entity' => 'sales_payments', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['document_id' => $doc, 'date' => now()->toDateString(), 'amount' => $amount, 'method' => $method]];
        }

        return ['id' => $doc, 'mutations' => $muts];
    }

    protected function stock(): float
    {
        return collect($this->api($this->admin)->getJson('/api/stock/levels')->json('data'))->where('product_id', $this->productId)->sum('quantity');
    }

    public function test_full_offline_cash_session_with_mixed_payments_and_z_report(): void
    {
        $session = $this->uuid();
        $t1 = $this->ticket($session, 2, [['cash', 1000]], 'C1-0001');            // 2 × 500 TTC
        $t2 = $this->ticket($session, 3, [['mobile_money', 1000], ['cash', 500]], 'C1-0002'); // mixte
        $t3 = $this->ticket($session, 1, [['card', 500]], 'C1-0003');

        // Toute la journée est envoyée d'un coup au retour du réseau, clôture comprise
        $results = $this->push($this->cashier, array_merge(
            [['entity' => 'pos_sessions', 'op' => 'upsert', 'record_id' => $session, 'data' => ['register_id' => $this->registerId, 'opening_cash' => 10000, 'status' => 'open']]],
            $t1['mutations'], $t2['mutations'], $t3['mutations'],
            [['entity' => 'pos_sessions', 'op' => 'upsert', 'record_id' => $session, 'data' => ['status' => 'closed', 'counted_cash' => 11400]]],
        ));
        foreach ($results as $r) {
            $this->assertSame('applied', $r['status'], json_encode($r));
        }

        $closed = end($results)['record'];
        $this->assertSame('closed', $closed['status']);
        $this->assertSame('Awa', $closed['user_name']);
        $this->assertEquals(3000, $closed['total_sales']);
        $this->assertSame(3, $closed['tickets_count']);
        $this->assertEquals(11500, $closed['expected_cash']);   // 10 000 + 1 000 + 500
        $this->assertEquals(-100, $closed['difference']);       // il manque 100 F

        // Numéros définitifs, référence provisoire conservée
        $doc = $this->api($this->admin)->getJson("/api/ventes/documents/{$t2['id']}")->json('data');
        $this->assertSame('TK-'.now()->year.'-00002', $doc['number']);
        $this->assertSame('C1-0002', $doc['reference']);
        $this->assertSame('paid', $doc['payment_status']);

        // Stock : 48 − 6
        $this->assertEquals(42, $this->stock());

        // Rapport Z
        $z = $this->api($this->cashier)->getJson("/api/pos/sessions/$session/report")->assertOk()->json('data');
        $this->assertEquals(1500, $z['by_method']['cash']['total']);
        $this->assertEquals(1000, $z['by_method']['mobile_money']['total']);
        $this->assertEquals(500, $z['by_method']['card']['total']);
        $this->assertEquals(6, $z['top_products'][0]['quantity']);

        // Session clôturée : plus modifiable
        $r = $this->push($this->cashier, [['entity' => 'pos_sessions', 'op' => 'upsert', 'record_id' => $session, 'data' => ['counted_cash' => 99999]]]);
        $this->assertEquals(11400, $r[0]['record']['counted_cash']);

        // Tableau de bord
        $w = collect($this->api($this->admin)->getJson('/api/dashboard')->json('data'))->keyBy('key');
        $this->assertEquals(3000, $w['pos_today']['value']);
        $this->assertEquals(3000, $w['revenue_month']['value']);
    }

    public function test_late_ticket_from_another_device_updates_closed_session(): void
    {
        $session = $this->uuid();
        $this->push($this->cashier, [
            ['entity' => 'pos_sessions', 'op' => 'upsert', 'record_id' => $session, 'data' => ['register_id' => $this->registerId, 'opening_cash' => 0]],
            ['entity' => 'pos_sessions', 'op' => 'upsert', 'record_id' => $session, 'data' => ['status' => 'closed', 'counted_cash' => 1000]],
        ]);
        $t = $this->ticket($session, 2, [['cash', 1000]], 'C1-0009');
        $this->push($this->cashier, $t['mutations']);

        $s = $this->api($this->admin)->getJson("/api/pos/sessions/$session")->json('data');
        $this->assertEquals(1000, $s['total_sales']);
        $this->assertEquals(0, $s['difference']);
    }

    public function test_cashier_cannot_create_invoices_and_pos_can_be_disabled(): void
    {
        $r = $this->push($this->cashier, [[
            'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(),
            'data' => ['type' => 'invoice', 'date' => now()->toDateString(), 'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 100]]],
        ]]);
        $this->assertSame('rejected', $r[0]['status']);

        $this->api($this->cashier)->getJson('/api/users')->assertStatus(403);

        // Super admin coupe la caisse pour cette société
        [, , $super] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        $this->api($super)->putJson("/api/admin/companies/{$this->companyId}/modules/pos", ['enabled' => false])->assertOk();
        $this->api($this->cashier)->getJson('/api/pos/registers')->assertStatus(403);
        $t = $this->ticket($this->uuid(), 1, [], 'C1-0010');
        $this->assertSame('rejected', $this->push($this->cashier, $t['mutations'])[0]['status']);
    }

    public function test_cfa_totals_match_shelf_prices(): void
    {
        // Prix HT saisis depuis un TTC rond : 500 F et 4 500 F
        $riz = $this->api($this->admin)->postJson('/api/core/products', ['name' => 'Riz 5kg', 'sale_price' => 3813.56, 'tax_rate' => 18])->json('data.id');
        $doc = $this->api($this->admin)->postJson('/api/ventes/documents', [
            'type' => 'invoice', 'date' => now()->toDateString(),
            'lines' => [
                ['product_id' => $this->productId, 'description' => 'Coca', 'quantity' => 4, 'unit_price' => 423.73, 'tax_rate' => 18],
                ['product_id' => $riz, 'description' => 'Riz', 'quantity' => 1, 'unit_price' => 3813.56, 'tax_rate' => 18],
            ],
        ])->assertCreated()->json('data');

        $this->assertEquals(2000, $doc['lines'][0]['total']);
        $this->assertEquals(4500, $doc['lines'][1]['total']);
        $this->assertEquals(6500, $doc['total']);
        $this->assertEquals($doc['total'], $doc['subtotal'] + $doc['tax_total']);
    }
    public function test_cashier_sees_only_own_sessions_and_registers_stay_in_company(): void
    {
        // Session ouverte par l'administrateur : invisible pour la caissière (rapport, clôture)
        $session = $this->uuid();
        $this->assertSame('applied', $this->push($this->admin, [['entity' => 'pos_sessions', 'op' => 'upsert', 'record_id' => $session, 'data' => ['register_id' => $this->registerId, 'opening_cash' => 5000]]])[0]['status']);
        $this->api($this->cashier)->getJson("/api/pos/sessions/$session/report")->assertNotFound();
        $this->api($this->cashier)->putJson("/api/pos/sessions/$session", ['status' => 'closed', 'counted_cash' => 0])->assertNotFound();
        $this->api($this->admin)->getJson("/api/pos/sessions/$session/report")->assertOk();

        // Caisse d'une autre société : refusée
        [, , $other] = $this->makeCompany('Concurrent');
        $foreign = $this->api($other)->postJson('/api/pos/registers', ['name' => 'Caisse X'])->assertCreated()->json('data.id');
        $this->api($this->cashier)->postJson('/api/pos/sessions', ['register_id' => $foreign, 'opening_cash' => 0])->assertStatus(422);
        $foreignWarehouse = $this->api($other)->postJson('/api/stock/warehouses', ['name' => 'Dépôt X'])->json('data.id');
        $this->api($this->admin)->postJson('/api/pos/registers', ['name' => 'Caisse 2', 'warehouse_id' => $foreignWarehouse])->assertStatus(422);
    }
}
