<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Role;
use App\Models\User;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\SupplierPrice;
use App\Modules\Core\Models\Product;
use Tests\TestCase;

class AchatsAvancesTest extends TestCase
{
    public function test_request_approval_order_partial_receipts_prices_and_replenishment(): void
    {
        [$company, $admin, $token] = $this->makeCompany('Quincaillerie Moderne');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/bootstrap')->assertOk()->assertJsonFragment(['purchase_requests']);
        $settings = $company->settings;
        $settings['purchase_approval_limit'] = 500000;
        $company->forceFill(['settings' => $settings])->save();
        $role = fn (string $n) => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', $n)->value('id');
        $user = fn (string $name, string $r) => User::create(['company_id' => $company->id, 'name' => $name, 'email' => strtolower(str_replace(' ', '', $name)).'@qm.ci', 'password' => 'secret123', 'role_id' => $role($r)]);
        $commercial = $user('Ali Commercial', 'Commercial');
        $buyer = $user('Bintou Acheteuse', 'Acheteur');
        $boss = $user('Directeur', 'Direction');
        $store = $user('Magasin', 'Magasinier');
        $capi = fn () => $this->api($commercial->createToken('c')->plainTextToken);
        $bapi = fn () => $this->api($buyer->createToken('b')->plainTextToken);
        $dapi = fn () => $this->api($boss->createToken('d')->plainTextToken);
        $sapi = fn () => $this->api($store->createToken('s')->plainTextToken);

        $cement = $api()->postJson('/api/core/products', ['name' => 'Ciment CPJ 45 (sac)', 'sale_price' => 6000, 'purchase_price' => 4500, 'tax_rate' => 18, 'min_stock' => 50, 'unit' => 'sac'])->json('data.id');
        $iron = $api()->postJson('/api/core/products', ['name' => 'Fer à béton 10 mm', 'sale_price' => 4000, 'purchase_price' => 3000, 'tax_rate' => 18, 'min_stock' => 100])->json('data.id');
        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Dépôt', 'is_default' => true])->json('data.id');
        $api()->postJson('/api/stock/moves', ['product_id' => $cement, 'warehouse_id' => $wh, 'quantity' => 20, 'type' => 'initial', 'date' => now()->toDateString()])->assertCreated();
        $stock = fn ($p) => (float) collect($api()->getJson('/api/stock/levels')->json('data'))->where('product_id', $p)->sum('quantity');
        $supA = $api()->postJson('/api/core/partners', ['name' => 'CIMAF', 'type' => 'supplier'])->json('data.id');
        $supB = $api()->postJson('/api/core/partners', ['name' => 'Sotaci', 'type' => 'supplier'])->json('data.id');

        // 1. Demande d'achat d'un commercial → refus sans motif impossible → approbation par la direction (au-delà du seuil)
        $req = $capi()->postJson('/api/achats/requests', ['title' => 'Chantier client Bassam', 'reason' => 'Commande ferme du client', 'needed_by' => now()->addWeek()->toDateString(),
            'lines' => [['product_id' => $cement, 'description' => 'Ciment CPJ 45 (sac)', 'quantity' => 200, 'estimated_price' => 4400, 'supplier_id' => $supA],
                ['product_id' => $iron, 'description' => 'Fer à béton 10 mm', 'quantity' => 150, 'estimated_price' => 2900]]])->assertCreated()->json('data');
        $this->assertStringStartsWith('DA-', $req['number']);
        $this->assertSame(200 * 4400 + 150 * 2900, (int) $req['estimated_total']);
        $capi()->putJson("/api/achats/requests/{$req['id']}", ['status' => 'approved'])->assertStatus(422);
        $capi()->putJson("/api/achats/requests/{$req['id']}", ['status' => 'submitted'])->assertOk();
        $this->assertTrue(AppNotification::where('user_id', $boss->id)->where('ref', $req['id'])->exists());
        $this->assertFalse(AppNotification::where('user_id', $buyer->id)->where('ref', $req['id'])->exists()); // au-delà de son seuil
        $bapi()->putJson("/api/achats/requests/{$req['id']}", ['status' => 'approved'])->assertStatus(422);
        $dapi()->putJson("/api/achats/requests/{$req['id']}", ['status' => 'rejected'])->assertStatus(422); // motif obligatoire
        $dapi()->putJson("/api/achats/requests/{$req['id']}", ['status' => 'approved'])->assertOk()->assertJsonPath('data.approver_id', $boss->id);
        $this->assertTrue(AppNotification::where('user_id', $commercial->id)->where('title', 'like', '%approuvée%')->exists());

        // 2. Commande : un bon par fournisseur (fournisseur indiqué, sinon celui choisi)
        $bapi()->postJson("/api/achats/requests/{$req['id']}/order")->assertStatus(422)->assertJsonFragment(['message' => 'Choisissez un fournisseur pour chaque article.']);
        $orders = $bapi()->postJson("/api/achats/requests/{$req['id']}/order", ['partner_id' => $supB])->assertOk()->json('data');
        $this->assertCount(2, $orders);
        $this->assertSame('ordered', $api()->getJson("/api/achats/requests/{$req['id']}")->json('data.status'));
        $poA = collect($orders)->firstWhere('partner_id', $supA);
        $poB = collect($orders)->firstWhere('partner_id', $supB);

        // 3. Seuil d'approbation des bons de commande (880 000 > 500 000)
        $bapi()->postJson("/api/achats/documents/{$poA['id']}/validate")->assertStatus(422);
        $bapi()->postJson("/api/achats/documents/{$poA['id']}/request-approval")->assertOk();
        $bapi()->postJson("/api/achats/documents/{$poA['id']}/approve")->assertForbidden();
        $dapi()->postJson("/api/achats/documents/{$poA['id']}/approve")->assertOk();
        $po = $bapi()->postJson("/api/achats/documents/{$poA['id']}/validate")->assertOk()->json('data');
        $this->assertSame('none', $po['receipt_status']);
        $bapi()->postJson("/api/achats/documents/{$poB['id']}/validate")->assertOk(); // 435 000 : sous le seuil
        // Tarifs appris
        $this->assertSame(4400.0, (float) SupplierPrice::withoutGlobalScopes()->where('partner_id', $supA)->where('product_id', $cement)->value('price'));
        $this->assertTrue((bool) SupplierPrice::withoutGlobalScopes()->where('product_id', $cement)->value('preferred'));

        // 4. Réceptions partielles par le magasinier
        $line = $po['lines'][0];
        $sapi()->postJson('/api/achats/receipts', ['order_id' => $po['id'], 'date' => now()->toDateString(), 'lines' => [['order_line_id' => $line['id'], 'quantity' => 250]]])->assertStatus(422);
        $r1 = $sapi()->postJson('/api/achats/receipts', ['order_id' => $po['id'], 'date' => now()->toDateString(), 'supplier_reference' => 'BL-7781',
            'lines' => [['order_line_id' => $line['id'], 'quantity' => 120]]])->assertCreated()->json('data');
        $this->assertStringStartsWith('REC-', $r1['number']);
        $this->assertSame(140.0, $stock($cement));
        $this->assertSame('partial', $api()->getJson("/api/achats/documents/{$po['id']}")->json('data.receipt_status'));
        $this->assertSame(120.0, (float) $api()->getJson("/api/achats/documents/{$po['id']}")->json('data.received.'.$line['id']));
        $this->assertSame(4400.0, (float) Product::withoutGlobalScopes()->find($cement)->purchase_price); // dernier coût d'achat
        $r2 = $sapi()->postJson('/api/achats/receipts', ['order_id' => $po['id'], 'date' => now()->toDateString(), 'lines' => [['order_line_id' => $line['id'], 'quantity' => 80]]])->assertCreated()->json('data');
        $this->assertSame('received', $api()->getJson("/api/achats/documents/{$po['id']}")->json('data.receipt_status'));
        $this->assertSame(220.0, $stock($cement));
        $api()->postJson("/api/achats/documents/{$po['id']}/cancel")->assertStatus(422); // réceptions existantes
        // Annulation d'une réception : le stock est corrigé
        $sapi()->putJson("/api/achats/receipts/{$r2['id']}", ['status' => 'cancelled'])->assertOk();
        $this->assertSame(140.0, $stock($cement));
        $this->assertSame('partial', $api()->getJson("/api/achats/documents/{$po['id']}")->json('data.receipt_status'));
        $sapi()->putJson("/api/achats/receipts/{$r2['id']}", ['status' => 'done'])->assertStatus(422);

        // 5. Facture fournisseur depuis le bon : pas de seconde entrée en stock
        $inv = $bapi()->postJson("/api/achats/documents/{$po['id']}/convert", ['type' => 'supplier_invoice'])->assertCreated()->json('data');
        $bapi()->postJson("/api/achats/documents/{$inv['id']}/validate")->assertOk();
        $this->assertSame(140.0, $stock($cement));

        // 6. Réapprovisionnement : le fer (0 en stock, 150 commandés, seuil 100) n'est pas proposé ; le ciment non plus (140 ≥ 50)
        $this->assertSame([], $api()->getJson('/api/achats/replenishment')->json('data'));
        $api()->postJson('/api/stock/moves', ['product_id' => $cement, 'warehouse_id' => $wh, 'quantity' => -120, 'type' => 'loss', 'date' => now()->toDateString()])->assertCreated();
        $sugg = $api()->getJson('/api/achats/replenishment')->json('data');
        $this->assertCount(1, $sugg);
        $this->assertSame([$cement, $supA, 4400.0], [$sugg[0]['product_id'], $sugg[0]['partner_id'], (float) $sugg[0]['unit_price']]);
        $this->assertSame(80.0, (float) $sugg[0]['quantity']); // 2 × 50 − 20
        $made = $api()->postJson('/api/achats/replenishment', ['items' => [['product_id' => $cement, 'partner_id' => $supA, 'quantity' => 80, 'unit_price' => 4400]]])->assertOk()->json('data');
        $this->assertSame('draft', $made[0]['status']);
        $this->assertSame(80.0, (float) $made[0]['lines'][0]['quantity']);

        // Droits : un commercial ne réceptionne pas, ne voit pas les bons de commande
        $capi()->postJson('/api/achats/receipts', ['order_id' => $poB['id'], 'date' => now()->toDateString(), 'lines' => []])->assertForbidden();
        $capi()->getJson('/api/achats/documents')->assertForbidden();
        $this->assertSame(1, PurchaseDocument::withoutGlobalScopes()->where('type', 'supplier_invoice')->count());
    }
}
