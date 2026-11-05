<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Restaurant\Models\Order;
use Tests\TestCase;

class RestaurantTest extends TestCase
{
    public function test_floor_orders_kitchen_flow_split_bills_cancellations_rights_and_stats(): void
    {
        [$company, , $token] = $this->makeCompany('Maquis Le Jardin');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['rs_order_lines']);
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $mk = function (string $name, string $roleName) use ($company, $role) {
            $u = User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower($name).'@jardin.ci', 'password' => 'secret123', 'role_id' => $role($roleName)]);

            return [$u, fn () => $this->api($u->createToken('t')->plainTextToken)];
        };
        [$awa, $waiter] = $mk('Awa', 'Serveur');
        [, $kitchen] = $mk('Chef', 'Cuisine / bar');

        // Carte : catégories Plats et Boissons, postes Cuisine et Bar
        $plats = $api()->postJson('/api/core/product-categories', ['name' => 'Plats'])->json('data.id');
        $boissons = $api()->postJson('/api/core/product-categories', ['name' => 'Boissons'])->json('data.id');
        $garba = $api()->postJson('/api/core/products', ['name' => 'Garba', 'category_id' => $plats, 'sale_price' => 1500, 'tax_rate' => 0, 'type' => 'service'])->json('data.id');
        $poulet = $api()->postJson('/api/core/products', ['name' => 'Poulet braisé', 'category_id' => $plats, 'sale_price' => 5000, 'tax_rate' => 0, 'type' => 'service'])->json('data.id');
        $biere = $api()->postJson('/api/core/products', ['name' => 'Bière 65cl', 'category_id' => $boissons, 'sale_price' => 1000, 'tax_rate' => 0, 'type' => 'service'])->json('data.id');
        $cuisine = $api()->postJson('/api/restaurant/stations', ['name' => 'Cuisine', 'kind' => 'kitchen'])->assertCreated()->json('data.id');
        $bar = $api()->postJson('/api/restaurant/stations', ['name' => 'Bar', 'kind' => 'bar', 'category_ids' => [$boissons]])->assertCreated()->json('data.id');
        $floor = $api()->postJson('/api/restaurant/floors', ['name' => 'Terrasse'])->assertCreated()->json('data.id');
        $t5 = $api()->postJson('/api/restaurant/tables', ['floor_id' => $floor, 'name' => '5', 'seats' => 4, 'x' => 2, 'y' => 3])->assertCreated()->json('data.id');
        $waiter()->postJson('/api/restaurant/tables', ['name' => '6'])->assertForbidden();

        // Prise de commande par la serveuse : prix du catalogue imposé, poste choisi par catégorie
        $order = $waiter()->postJson('/api/restaurant/orders', ['table_id' => $t5, 'guests' => 3])->assertCreated()->json('data');
        $this->assertSame(['open', 'dine_in', $awa->id, 'Awa'], [$order['status'], $order['kind'], $order['waiter_id'], $order['waiter_name']]);
        $this->assertStringStartsWith('CO-', $order['number']);
        $line = fn (array $l) => $waiter()->postJson('/api/restaurant/order-lines', $l + ['order_id' => $order['id']]);
        $l1 = $line(['product_id' => $garba, 'quantity' => 2, 'unit_price' => 1, 'course' => 2, 'note' => 'Piment à part', 'status' => 'sent'])->assertCreated()->json('data');
        $this->assertEquals([1500, $cuisine, 'sent'], [$l1['unit_price'], $l1['station_id'], $l1['status']]);
        $this->assertNotNull($l1['sent_at']);
        $l2 = $line(['product_id' => $biere, 'quantity' => 3, 'course' => 0, 'status' => 'sent'])->assertCreated()->json('data');
        $this->assertSame($bar, $l2['station_id']);
        $l3 = $line(['product_id' => $poulet, 'quantity' => 1, 'course' => 2])->assertCreated()->json('data');
        $this->assertSame('pending', $l3['status']);
        $line(['description' => 'Plat du jour', 'quantity' => 1, 'unit_price' => 3000])->assertStatus(422); // hors carte : responsable seulement
        $kitchen()->postJson('/api/restaurant/order-lines', ['order_id' => $order['id'], 'product_id' => $garba, 'quantity' => 1])->assertStatus(422);

        // Ligne envoyée : contenu verrouillé ; non envoyée : modifiable puis supprimable
        $waiter()->putJson("/api/restaurant/order-lines/{$l1['id']}", ['quantity' => 5])->assertStatus(422)->assertJsonFragment(['message' => 'Ligne déjà envoyée en cuisine : annulez-la et ajoutez-en une nouvelle.']);
        $waiter()->putJson("/api/restaurant/order-lines/{$l3['id']}", ['quantity' => 2])->assertOk();
        $waiter()->deleteJson("/api/restaurant/order-lines/{$l1['id']}")->assertStatus(422);
        $waiter()->putJson("/api/restaurant/order-lines/{$l3['id']}", ['status' => 'sent'])->assertOk();

        // Cuisine : en préparation → prête (la serveuse est prévenue) ; ne peut ni annuler ni modifier
        $kitchen()->putJson("/api/restaurant/order-lines/{$l1['id']}", ['status' => 'preparing'])->assertOk();
        $kitchen()->putJson("/api/restaurant/order-lines/{$l1['id']}", ['status' => 'cancelled', 'cancel_reason' => 'Rupture'])->assertStatus(422);
        $kitchen()->putJson("/api/restaurant/order-lines/{$l1['id']}", ['status' => 'sent'])->assertStatus(422);
        $kitchen()->putJson("/api/restaurant/order-lines/{$l1['id']}", ['status' => 'ready'])->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertTrue(AppNotification::withoutGlobalScopes()->where('user_id', $awa->id)->where('title', 'Table 5 : prêt à servir')->exists());
        $waiter()->putJson("/api/restaurant/order-lines/{$l1['id']}", ['status' => 'served'])->assertOk();
        $kitchen()->putJson("/api/restaurant/order-lines/{$l2['id']}", ['status' => 'ready'])->assertOk();

        // Annulation d'une ligne envoyée : motif obligatoire ; plat servi : responsable seulement
        $waiter()->putJson("/api/restaurant/order-lines/{$l3['id']}", ['status' => 'cancelled'])->assertStatus(422)->assertJsonFragment(['message' => 'Indiquez le motif de l\'annulation.']);
        $waiter()->putJson("/api/restaurant/order-lines/{$l1['id']}", ['status' => 'cancelled', 'cancel_reason' => 'Client parti'])->assertStatus(422);
        $waiter()->putJson("/api/restaurant/order-lines/{$l3['id']}", ['status' => 'cancelled', 'cancel_reason' => 'Trop d\'attente'])->assertOk();

        // Fermeture impossible tant qu'il reste à payer
        $waiter()->putJson("/api/restaurant/orders/{$order['id']}", ['status' => 'closed'])->assertStatus(422)->assertJsonFragment(['message' => 'Il reste 6 000 à encaisser sur cette commande.']);

        // Addition partagée : 1er ticket = le garba de deux convives, 2e ticket = les bières
        $reg = $api()->postJson('/api/pos/registers', ['name' => 'Caisse bar', 'code' => 'C1'])->json('data.id');
        $session = $this->uuid();
        $push = fn ($mut) => $waiter()->postJson('/api/sync/push', ['mutations' => array_map(fn ($m) => $m + ['id' => $this->uuid()], $mut)])->assertOk()->json('results');
        $push([['entity' => 'pos_sessions', 'op' => 'upsert', 'record_id' => $session, 'data' => ['register_id' => $reg, 'opening_cash' => 0, 'status' => 'open']]]);
        $bill = function (array $lines, array $qty, float $amount) use ($push, $session, $order) {
            $doc = $this->uuid();
            $r = $push([
                ['entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $doc, 'data' => ['type' => 'receipt', 'date' => now()->toDateString(), 'status' => 'validated', 'pos_session_id' => $session,
                    'rs_order_id' => $order['id'], 'rs_bill' => $qty, 'lines' => $lines]],
                ['entity' => 'sales_payments', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => ['document_id' => $doc, 'date' => now()->toDateString(), 'amount' => $amount, 'method' => 'cash']],
            ]);
            $this->assertSame(['applied', 'applied'], array_column($r, 'status'), json_encode($r));

            return $doc;
        };
        $bill([['product_id' => $garba, 'description' => 'Garba', 'quantity' => 2, 'unit_price' => 1500, 'tax_rate' => 0]], [$l1['id'] => 2], 3000);
        $this->assertSame('open', Order::withoutGlobalScopes()->find($order['id'])->status);
        $second = $bill([['product_id' => $biere, 'description' => 'Bière 65cl', 'quantity' => 3, 'unit_price' => 1000, 'tax_rate' => 0]], [$l2['id'] => 3], 3000);
        $o = Order::withoutGlobalScopes()->find($order['id']);
        $this->assertSame('closed', $o->status);
        $this->assertNotNull($o->closed_at);
        $waiter()->putJson("/api/restaurant/order-lines/{$l2['id']}", ['status' => 'cancelled', 'cancel_reason' => 'x'])->assertStatus(422);
        $waiter()->postJson('/api/restaurant/order-lines', ['order_id' => $order['id'], 'product_id' => $biere, 'quantity' => 1])->assertStatus(422);

        // Ticket annulé : la commande se rouvre
        foreach (\App\Modules\Ventes\Models\SalesPayment::withoutGlobalScopes()->where('document_id', $second)->pluck('id') as $pid) {
            $api()->deleteJson("/api/ventes/payments/$pid")->assertOk();
        }
        $api()->postJson("/api/ventes/documents/$second/cancel")->assertOk();
        $this->assertSame('open', Order::withoutGlobalScopes()->find($order['id'])->status);
        $waiter()->putJson("/api/restaurant/order-lines/{$l2['id']}", ['status' => 'cancelled', 'cancel_reason' => 'Offertes par le patron'])->assertOk();
        $this->assertSame('closed', Order::withoutGlobalScopes()->find($order['id'])->status);

        // Commande à emporter annulée : motif exigé dès qu'elle est partie en cuisine
        $take = $waiter()->postJson('/api/restaurant/orders', ['customer_name' => 'Koffi'])->assertCreated()->json('data');
        $this->assertSame('takeaway', $take['kind']);
        $tl = $waiter()->postJson('/api/restaurant/order-lines', ['order_id' => $take['id'], 'product_id' => $poulet, 'quantity' => 1, 'status' => 'sent'])->json('data');
        $waiter()->putJson("/api/restaurant/orders/{$take['id']}", ['status' => 'cancelled'])->assertStatus(422);
        $waiter()->putJson("/api/restaurant/orders/{$take['id']}", ['status' => 'cancelled', 'cancel_reason' => 'Client injoignable'])->assertOk();
        $this->assertSame('cancelled', $api()->getJson("/api/restaurant/order-lines/{$tl['id']}")->json('data.status'));
        $waiter()->putJson("/api/restaurant/orders/{$take['id']}", ['status' => 'open'])->assertStatus(422);
        $waiter()->deleteJson("/api/restaurant/orders/{$take['id']}")->assertStatus(422);

        // Statistiques (responsable)
        $waiter()->getJson('/api/restaurant/stats')->assertForbidden();
        $s = $api()->getJson('/api/restaurant/stats')->assertOk()->json('data');
        $this->assertSame([1, 3, 3000.0, 1000.0], [$s['orders'], $s['covers'], (float) $s['revenue'], (float) $s['per_cover']]);
        $this->assertSame('Garba', $s['top'][0]['name']);
        $this->assertSame(2, $s['cancelled_lines']);
        $this->assertSame('Awa', $s['waiters'][0]['name']);
        $this->assertSame(['Cuisine', 1], [$s['prep'][0]['station'], $s['prep'][0]['count']]);
        $w = collect($api()->getJson('/api/dashboard')->json('data'))->keyBy('key');
        $this->assertSame([0, 3], [$w['rs_open']['value'], $w['rs_covers']['value']]);
    }
}
