<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class ProductionTest extends TestCase
{
    public function test_boms_costs_orders_stock_recipes_needs_and_rights(): void
    {
        [$company, , $token] = $this->makeCompany('Boulangerie Moderne');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['mrp_orders']);
        $api()->putJson('/api/company', ['settings' => ['mrp_labour_rate' => 1200]])->assertOk();
        $op = User::create(['company_id' => $company->id, 'name' => 'Issa', 'email' => 'issa@boul.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Opérateur de production')->value('id')]);
        $oapi = fn () => $this->api($op->createToken('o')->plainTextToken);

        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Atelier', 'is_default' => true])->json('data.id');
        $mk = fn (array $p) => $api()->postJson('/api/core/products', $p + ['tax_rate' => 0])->assertCreated()->json('data.id');
        $farine = $mk(['name' => 'Farine (kg)', 'unit' => 'kg', 'purchase_price' => 600, 'sale_price' => 0, 'track_stock' => true]);
        $levure = $mk(['name' => 'Levure (g)', 'unit' => 'g', 'purchase_price' => 5, 'sale_price' => 0, 'track_stock' => true]);
        $sel = $mk(['name' => 'Sel (kg)', 'unit' => 'kg', 'purchase_price' => 300, 'sale_price' => 0, 'track_stock' => true]);
        $pain = $mk(['name' => 'Baguette', 'sale_price' => 150, 'purchase_price' => 0, 'track_stock' => true]);
        $sandwich = $mk(['name' => 'Sandwich poulet', 'type' => 'service', 'sale_price' => 1500]);
        $poulet = $mk(['name' => 'Poulet effiloché (kg)', 'unit' => 'kg', 'purchase_price' => 4000, 'sale_price' => 0, 'track_stock' => true]);
        foreach ([[$farine, 50], [$levure, 1000], [$sel, 2], [$poulet, 5]] as [$p, $q]) {
            $api()->postJson('/api/stock/moves', ['product_id' => $p, 'warehouse_id' => $wh, 'quantity' => $q, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();
        }
        $stock = fn (string $p) => collect($api()->getJson('/api/stock/levels')->json('data'))->where('product_id', $p)->sum('quantity');

        // Nomenclature : 40 baguettes = 10 kg de farine (+2 % de perte), 200 g de levure, 0,2 kg de sel, 90 min de travail
        $bom = $api()->postJson('/api/production/boms', ['product_id' => $pain, 'quantity' => 40, 'labour_minutes' => 90, 'extra_cost' => 400, 'lines' => [
            ['product_id' => $farine, 'quantity' => 10, 'loss_percent' => 2], ['product_id' => $levure, 'quantity' => 200], ['product_id' => $sel, 'quantity' => 0.2],
        ]])->assertCreated()->json('data');
        $this->assertSame('Baguette', $bom['name']);
        $cost = $api()->getJson("/api/production/boms/{$bom['id']}/cost")->assertOk()->json('data');
        // (10,2×600 + 200×5 + 0,2×300 + 1,5 h × 1200 + 400) / 40 = (6120+1000+60+1800+400)/40 = 234,5
        $this->assertEquals([234.5, 179.5, 45.0], [$cost['unit'], $cost['material'], $cost['labour']]);
        $this->assertEquals(-84.5, $cost['margin']);
        $api()->postJson('/api/production/boms', ['product_id' => $farine, 'quantity' => 1, 'lines' => [['product_id' => $farine, 'quantity' => 1]]])->assertStatus(422);
        $api()->postJson('/api/production/boms', ['product_id' => $farine, 'quantity' => 1, 'lines' => [['product_id' => $pain, 'quantity' => 1]]])->assertStatus(422)
            ->assertJsonFragment(['message' => 'Boucle de nomenclature : un composant est lui-même fabriqué à partir de ce produit.']);
        $oapi()->postJson('/api/production/boms', ['product_id' => $sandwich, 'quantity' => 1, 'lines' => [['product_id' => $pain, 'quantity' => 1]]])->assertForbidden();

        // Ordre de fabrication de 120 baguettes : composants calculés
        $oapi()->postJson('/api/production/orders', ['product_id' => $pain, 'quantity' => 120])->assertStatus(422);
        $of = $api()->postJson('/api/production/orders', ['product_id' => $pain, 'quantity' => 120, 'planned_date' => now()->toDateString()])->assertCreated()->json('data');
        $this->assertStringStartsWith('OF-', $of['number']);
        $this->assertEquals([30.6, 600, 0.6], array_column($of['components'], 'planned'));
        $this->assertSame(270, $of['labour_minutes']);
        $api()->putJson("/api/production/orders/{$of['id']}", ['quantity' => 80])->assertOk()->assertJsonPath('data.components.0.planned', 20.4);
        $oapi()->putJson("/api/production/orders/{$of['id']}", ['status' => 'confirmed'])->assertStatus(422);
        $api()->putJson("/api/production/orders/{$of['id']}", ['status' => 'confirmed'])->assertOk();

        // Besoins : 20,4 kg de farine pour 50 en stock
        $needs = collect($api()->getJson('/api/production/needs')->assertOk()->json('data'))->keyBy('product_id');
        $this->assertEquals([20.4, 50, 0], [$needs[$farine]['needed'], $needs[$farine]['stock'], $needs[$farine]['missing']]);

        // L'opérateur démarre puis termine : 78 baguettes, 21 kg de farine réellement utilisés
        $oapi()->putJson("/api/production/orders/{$of['id']}", ['quantity' => 500])->assertStatus(422);
        $oapi()->putJson("/api/production/orders/{$of['id']}", ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $done = $oapi()->putJson("/api/production/orders/{$of['id']}", ['status' => 'done', 'produced_qty' => 78, 'components' => [['product_id' => $farine, 'consumed' => 21]]])->assertOk()->json('data');
        $this->assertSame('done', $done['status']);
        $this->assertEquals(29.0, $stock($farine));                 // 50 − 21
        $this->assertEquals(1000 - 400 * 78 / 80, $stock($levure));  // prorata de la quantité produite
        $this->assertEquals(78, $stock($pain));
        // Coût : matières 21×600 + 390×5 + 0,39×300 = 14 667 ; MO 180 min = 3 600 ; frais 800 → 19 067 / 78
        $this->assertEqualsWithDelta(19067 / 78, $done['unit_cost'], 0.01);
        $this->assertEqualsWithDelta(244.45, (float) $api()->getJson("/api/core/products/$pain")->json('data.purchase_price'), 0.01);
        $oapi()->putJson("/api/production/orders/{$of['id']}", ['produced_qty' => 100])->assertStatus(422);
        $api()->deleteJson("/api/production/orders/{$of['id']}")->assertStatus(422);

        // Recette vendue : chaque sandwich consomme une baguette et 80 g de poulet, remis si le ticket est annulé
        $api()->postJson('/api/production/boms', ['product_id' => $pain, 'kind' => 'kit', 'quantity' => 1, 'lines' => [['product_id' => $farine, 'quantity' => 1]]])->assertStatus(422);
        $api()->postJson('/api/production/boms', ['product_id' => $sandwich, 'kind' => 'kit', 'quantity' => 1, 'lines' => [['product_id' => $pain, 'quantity' => 1], ['product_id' => $poulet, 'quantity' => 0.08]]])->assertCreated();
        $api()->postJson('/api/production/orders', ['product_id' => $sandwich, 'quantity' => 5])->assertStatus(422);
        $inv = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'date' => now()->toDateString(), 'lines' => [
            ['product_id' => $sandwich, 'description' => 'Sandwich poulet', 'quantity' => 10, 'unit_price' => 1500, 'tax_rate' => 0],
            ['product_id' => $pain, 'description' => 'Baguette', 'quantity' => 5, 'unit_price' => 150, 'tax_rate' => 0],
        ]])->json('data');
        $api()->postJson("/api/ventes/documents/{$inv['id']}/validate")->assertOk();
        $this->assertEquals([63, 4.2], [$stock($pain), $stock($poulet)]); // 78 − 10 (sandwichs) − 5 (vendues seules)
        $api()->postJson("/api/ventes/documents/{$inv['id']}/cancel")->assertOk();
        $this->assertEquals([78, 5], [$stock($pain), $stock($poulet)]);

        // Tableau de bord
        $w = collect($api()->getJson('/api/dashboard')->json('data'))->keyBy('key');
        $this->assertSame(0, $w['mrp_running']['value']);
    }
}
