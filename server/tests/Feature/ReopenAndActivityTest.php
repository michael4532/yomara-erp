<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Rouvrir ou supprimer une facture validée et payée (droit sensible) + historique d'activité de tout l'ERP. */
class ReopenAndActivityTest extends TestCase
{
    public function test_paid_invoice_can_be_reopened_edited_revalidated_or_purged(): void
    {
        [$company, , $token] = $this->makeCompany('Boutique Reprise');
        $api = fn () => $this->api($token);
        $product = $api()->postJson('/api/core/products', ['name' => 'Ciment 50kg', 'sale_price' => 5000, 'purchase_price' => 4000, 'tax_rate' => 0])->json('data.id');
        $client = $api()->postJson('/api/core/partners', ['name' => 'NSI', 'type' => 'customer'])->json('data.id');

        $doc = $api()->postJson('/api/ventes/documents', ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->toDateString(),
            'lines' => [['product_id' => $product, 'description' => 'Ciment', 'quantity' => 4, 'unit_price' => 5000, 'tax_rate' => 0]]])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^BR-[A-HJ-NP-Z2-9]{6}$/', $doc['reference']); // référence automatique : initiales + code
        $inv = $api()->postJson("/api/ventes/documents/{$doc['id']}/validate")->assertOk()->json('data');
        $number = $inv['number'];
        $api()->postJson('/api/ventes/payments', ['document_id' => $inv['id'], 'date' => now()->toDateString(), 'amount' => 20000, 'method' => 'mobile_money'])->assertCreated();
        $this->assertSame('paid', DB::table('trade_documents')->where('id', $inv['id'])->value('payment_status'));
        $stock = fn () => (float) DB::table('stock_moves')->where('document_id', $inv['id'])->sum('quantity');
        $this->assertEquals(-4, $stock());

        // Un commercial (« ventes.* » compris) n'a pas ce droit : il est accordé nommément
        $roleId = Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Commercial')->value('id');
        $sales = User::create(['company_id' => $company->id, 'name' => 'Awa', 'email' => 'awa@reprise.ci', 'password' => 'secret123', 'role_id' => $roleId]);
        $salesApi = fn () => $this->api($sales->fresh()->createToken('t')->plainTextToken);
        $salesApi()->postJson("/api/ventes/documents/{$inv['id']}/reopen")->assertForbidden();
        $sales->forceFill(['role_id' => null])->save();
        Role::withoutGlobalScopes()->where('id', $roleId)->update(['permissions' => json_encode(['ventes.*', 'core.*'])]);
        $sales->forceFill(['role_id' => $roleId])->save();
        $salesApi()->postJson("/api/ventes/documents/{$inv['id']}/reopen")->assertForbidden(); // joker ≠ droit sensible

        // L'administrateur rouvre la facture payée : brouillon, même numéro, paiement conservé, stock rétabli
        $r = $api()->postJson("/api/ventes/documents/{$inv['id']}/reopen", ['reason' => 'Erreur de quantité'])->assertOk()->json('data');
        $this->assertSame('draft', $r['status']);
        $this->assertSame($number, $r['number']);
        $this->assertEquals(20000, $r['amount_paid']);
        $this->assertEquals(0, $stock());

        // Correction des lignes puis nouvelle validation : même numéro, stock et compta repassés une seule fois
        $api()->putJson("/api/ventes/documents/{$inv['id']}", ['lines' => [['product_id' => $product, 'description' => 'Ciment', 'quantity' => 5, 'unit_price' => 5000, 'tax_rate' => 0]]])->assertOk();
        $v = $api()->postJson("/api/ventes/documents/{$inv['id']}/validate")->assertOk()->json('data');
        $this->assertSame($number, $v['number']);
        $this->assertEquals(25000, $v['total']);
        $this->assertSame('partial', $v['payment_status']);
        $this->assertEquals(-5, $stock());

        // Le droit est donné à Awa par l'administrateur : elle peut supprimer la facture validée et payée
        $sales->forceFill(['permission_grants' => ['ventes.reopen']])->save();
        $salesApi()->postJson("/api/ventes/documents/{$inv['id']}/purge", ['reason' => 'Doublon'])->assertOk();
        $this->assertNotNull(DB::table('trade_documents')->where('id', $inv['id'])->value('deleted_at'));
        $this->assertEquals(0, $stock());
        $this->assertSame(0, DB::table('payments')->where('document_id', $inv['id'])->whereNull('deleted_at')->count());

        // Historique : création, validation, paiement, réouverture (avec la facture d'avant), suppression (avec ses paiements)
        $sales->forceFill(['permission_grants' => ['ventes.reopen']])->save();
        $salesApi()->getJson('/api/activity')->assertForbidden();
        $log = collect($api()->getJson('/api/activity?subject_id='.$inv['id'])->assertOk()->json('data'));
        $actions = $log->pluck('action')->all();
        foreach (['sales_documents.created', 'document.validated', 'document.reopened', 'document.purged'] as $a) {
            $this->assertContains($a, $actions);
        }
        $reopen = $log->firstWhere('action', 'document.reopened');
        $this->assertSame('Erreur de quantité', $reopen['reason']);
        $this->assertEquals(20000, $reopen['before']['total']);
        $this->assertSame('Admin Boutique Reprise', $reopen['user']);
        $purge = $log->firstWhere('action', 'document.purged');
        $this->assertSame('Awa', $purge['user']);
        $this->assertEquals(20000, $purge['before']['payments'][0]['amount']);
        $this->assertSame('ventes', $purge['module']);

        // Tout l'ERP : le contact créé apparaît aussi, filtrable par module et par personne
        $all = collect($api()->getJson('/api/activity?module=core')->assertOk()->json('data'));
        $this->assertTrue($all->contains(fn ($row) => $row['action'] === 'partners.created' && $row['subject'] === 'NSI'));
        $meta = $api()->getJson('/api/activity/meta')->assertOk()->json();
        $this->assertContains('Awa', array_column($meta['users'], 'name'));
    }

    public function test_updates_list_changed_fields_once_per_request(): void
    {
        [, , $token] = $this->makeCompany('Journal');
        $id = $this->api($token)->postJson('/api/core/partners', ['name' => 'Kouassi', 'type' => 'customer'])->json('data.id');
        $this->api($token)->putJson("/api/core/partners/$id", ['name' => 'Kouassi & Fils', 'phone' => '+225 07 00 00 00'])->assertOk();
        $rows = collect($this->api($token)->getJson("/api/activity?subject_id=$id")->json('data'));
        $this->assertCount(2, $rows);
        $upd = $rows->firstWhere('kind', 'updated');
        $this->assertEqualsCanonicalizing(['name', 'phone'], $upd['fields']);
        $this->assertSame(['Kouassi', 'Kouassi & Fils'], $upd['changes']['name']);
        $this->assertSame('Modification', $upd['label']);
    }

    public function test_reference_initials_and_paystack_open_transaction_stays_pending(): void
    {
        $this->assertStringStartsWith('YT-', \App\Modules\Core\Models\TradeDocument::autoReference('Yomara Technologies'));
        $this->assertStringStartsWith('NNSI-', \App\Modules\Core\Models\TradeDocument::autoReference('NSI - Next Sustainable Initiatives SARL'));
        $this->assertStringStartsWith('KOU-', \App\Modules\Core\Models\TradeDocument::autoReference('Kouassi'));

        $gw = new \App\Models\PaymentGateway(['driver' => 'paystack', 'credentials' => ['secret_key' => 'sk_live_x']]);
        $tx = new \App\Models\PaymentTransaction(['reference' => 'abc', 'amount' => 1000]);
        $remote = null;
        \Illuminate\Support\Facades\Http::fake(['api.paystack.co/*' => function () use (&$remote) {
            return \Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['status' => $remote]]);
        }]);
        foreach (['abandoned' => 'pending', 'ongoing' => 'pending', 'success' => 'paid', 'failed' => 'failed'] as $remote => $expected) {
            $this->assertSame($expected, (new \App\Payments\Gateways\Paystack($gw))->verify($tx), $remote);
        }
    }
}
