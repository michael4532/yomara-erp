<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Abonnements\Models\Subscription;
use App\Modules\Abonnements\SubscriptionService;
use App\Modules\Achats\Models\PurchaseDocument;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Contre-revue des correctifs du jour : régressions et contournements. */
class ReviewControlFixesTest extends TestCase
{
    public function test_waiter_sends_manager_line_and_keeps_manager_price(): void
    {
        [$company, , $token] = $this->makeCompany('Maquis Revue');
        $api = fn () => $this->api($token);
        $api()->getJson('/api/bootstrap')->assertOk();
        $waiter = User::create(['company_id' => $company->id, 'name' => 'Awa', 'email' => 'awa@revue.ci', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Serveur')->value('id')]);
        $wapi = fn () => $this->api($waiter->createToken('w')->plainTextToken);
        $poulet = $api()->postJson('/api/core/products', ['name' => 'Poulet', 'sale_price' => 5000, 'tax_rate' => 0, 'type' => 'service'])->json('data.id');
        $order = $wapi()->postJson('/api/restaurant/orders', ['guests' => 2])->assertCreated()->json('data.id');

        // Plat du jour ajouté par le responsable : la serveuse peut l'envoyer en cuisine, prix inchangé
        $custom = $api()->postJson('/api/restaurant/order-lines', ['order_id' => $order, 'description' => 'Plat du jour', 'quantity' => 1, 'unit_price' => 3000])->assertCreated()->json('data');
        $this->assertSame('pending', $custom['status']);
        $sent = $wapi()->putJson("/api/restaurant/order-lines/{$custom['id']}", ['status' => 'sent'])->assertOk()->json('data');
        $this->assertEquals([3000, 'sent'], [$sent['unit_price'], $sent['status']]);

        // Remise du responsable sur un article de la carte : conservée quand la serveuse change la quantité
        $promo = $api()->postJson('/api/restaurant/order-lines', ['order_id' => $order, 'product_id' => $poulet, 'quantity' => 1, 'unit_price' => 4000])->assertCreated()->json('data');
        $this->assertEquals(4000, $wapi()->putJson("/api/restaurant/order-lines/{$promo['id']}", ['quantity' => 2, 'unit_price' => 1])->assertOk()->json('data.unit_price'));
    }

    public function test_concurrent_subscription_run_does_not_bill_next_period_early(): void
    {
        [$company, , $token] = $this->makeCompany('Abonnements Revue');
        $api = fn () => $this->api($token);
        $client = $api()->postJson('/api/core/partners', ['name' => 'Client', 'type' => 'customer'])->json('data.id');
        $id = $this->uuid();
        $r = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'op' => 'upsert', 'entity' => 'ab_subscriptions', 'record_id' => $id, 'data' => [
            'partner_id' => $client, 'name' => 'Maintenance', 'start_date' => now()->toDateString(), 'interval_unit' => 'month', 'interval_count' => 1,
            'lines' => [['description' => 'Forfait', 'quantity' => 1, 'unit_price' => 1000, 'tax_rate' => 0]]]]]])->assertOk()->json('results.0');
        $this->assertSame('applied', $r['status'], json_encode($r));

        app()->instance('tenant', $company);
        $svc = app(SubscriptionService::class);
        $stale = Subscription::withoutGlobalScopes()->find($id); // lu par la seconde tâche avant que la première ne facture
        $this->assertNotNull($svc->generate($company, Subscription::withoutGlobalScopes()->find($id), now()->toDateString()));
        $this->assertNull($svc->generate($company, $stale, now()->toDateString()));
        $this->assertSame(1, DB::table('ab_invoices')->where('subscription_id', $id)->count());
        $this->assertSame(['invoices' => 0, 'errors' => 0], $svc->runDue($company));
    }

    public function test_purchase_order_supplier_change_drops_approval(): void
    {
        [$company, , $token] = $this->makeCompany('Achats Revue');
        $api = fn () => $this->api($token);
        $settings = $company->settings;
        $settings['purchase_approval_limit'] = 1000;
        $company->forceFill(['settings' => $settings])->save();
        $supA = $api()->postJson('/api/core/partners', ['name' => 'Fournisseur A', 'type' => 'supplier'])->json('data.id');
        $supB = $api()->postJson('/api/core/partners', ['name' => 'Fournisseur B', 'type' => 'supplier'])->json('data.id');
        $po = $api()->postJson('/api/achats/documents', ['type' => 'purchase_order', 'partner_id' => $supA, 'date' => now()->toDateString(),
            'lines' => [['description' => 'Ciment', 'quantity' => 4, 'unit_price' => 500, 'tax_rate' => 0]]])->assertCreated()->json('data');
        $api()->postJson("/api/achats/documents/{$po['id']}/approve")->assertOk();
        $this->assertNotNull(PurchaseDocument::withoutGlobalScopes()->find($po['id'])->approved_at);
        $api()->putJson("/api/achats/documents/{$po['id']}", ['partner_id' => $supB])->assertOk();
        $this->assertNull(PurchaseDocument::withoutGlobalScopes()->find($po['id'])->approved_at);
    }
}
