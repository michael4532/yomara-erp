<?php

namespace Tests\Feature;

use App\Messaging\Providers\Smtp;
use App\Models\AppNotification;
use App\Modules\Achats\Models\PurchaseDocument;
use App\Modules\Achats\Models\SupplierPrice;
use App\Modules\Core\Models\Product;
use Carbon\Carbon;
use Illuminate\Mail\Transport\ArrayTransport;
use Tests\TestCase;

class ApproTest extends TestCase
{
    protected ArrayTransport $mails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mails = new ArrayTransport;
        Smtp::$transportFactory = fn () => $this->mails;
    }

    protected function tearDown(): void
    {
        Smtp::$transportFactory = null;
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function env(): array
    {
        [$company, , $token] = $this->makeCompany('Électricité Générale Koné');
        $api = fn () => $this->api($token);
        $api()->putJson('/api/messaging/channels/email', ['provider' => 'smtp', 'settings' => [
            'host' => 'mail.k.ci', 'port' => '465', 'encryption' => 'ssl', 'username' => 'k@k.ci', 'password' => 'x', 'from_email' => 'achats@egk.ci', 'from_name' => 'EGK',
        ]])->assertOk();
        $cn = $api()->postJson('/api/core/partners', ['name' => 'Shanghai Electric Supply', 'type' => 'supplier', 'country' => 'CN', 'email' => 'sales@ses.cn'])->json('data.id');
        $fr = $api()->postJson('/api/core/partners', ['name' => 'Legrand Export', 'type' => 'supplier', 'country' => 'FR', 'email' => 'export@legrand.fr'])->json('data.id');
        $ci = $api()->postJson('/api/core/partners', ['name' => 'Électro Plateau', 'type' => 'supplier'])->json('data.id');
        $cable = $api()->postJson('/api/core/products', ['name' => 'Câble U-1000 R2V 3G2,5 (rouleau 100 m)', 'sale_price' => 30000, 'purchase_price' => 15000, 'tax_rate' => 18])->json('data.id');
        $disj = $api()->postJson('/api/core/products', ['name' => 'Disjoncteur 20 A', 'sale_price' => 3500, 'purchase_price' => 1200, 'tax_rate' => 18])->json('data.id');

        return compact('company', 'api', 'cn', 'fr', 'ci', 'cable', 'disj');
    }

    public function test_import_landed_cost_apply_and_order(): void
    {
        ['api' => $api, 'cn' => $cn, 'cable' => $cable, 'disj' => $disj] = $this->env();
        $f = $api()->postJson('/api/appro/imports', ['title' => 'Câbles et disjoncteurs — Chine', 'partner_id' => $cn, 'origin_country' => 'Chine', 'currency' => 'USD', 'exchange_rate' => 600,
            'incoterm' => 'FOB', 'transport' => 'sea', 'allocation' => 'value', 'eta' => now()->addDays(40)->toDateString(), 'forwarder' => 'Bolloré Transport & Logistics',
            'lines' => [
                ['product_id' => $cable, 'description' => 'Câble 3G2,5 rouleau 100 m', 'hs_code' => '8544.49', 'quantity' => 100, 'unit_price' => 20, 'weight' => 5, 'duty_rate' => 20],
                ['product_id' => $disj, 'description' => 'Disjoncteur 20 A', 'hs_code' => '8536.20', 'quantity' => 500, 'unit_price' => 2, 'weight' => 0.2, 'duty_rate' => 10],
            ],
            'costs' => [
                ['type' => 'freight', 'amount' => 360000], ['type' => 'insurance', 'amount' => 18000], ['type' => 'transit', 'amount' => 150000],
                ['type' => 'port', 'amount' => 90000], ['type' => 'local_transport', 'amount' => 60000, 'allocation' => 'weight'],
            ]])->assertCreated()->json('data');
        $this->assertStringStartsWith('IMP-', $f['number']);
        $t = $f['totals'];
        $this->assertEquals(1800000, $t['value']);
        $this->assertEquals(2178000, $t['cif']);
        $this->assertEquals(363000, $t['duty']);
        $this->assertEquals(2841000, $t['landed']);
        $this->assertEquals(1.578, $t['coefficient']);
        $this->assertEquals(457380, $t['vat_import']);
        $l = collect($f['lines'])->keyBy('product_id');
        $this->assertEquals(19524, $l[$cable]['unit_landed']);
        $this->assertEquals(1777.2, $l[$disj]['unit_landed']);
        $this->assertEquals(50000, $l[$cable]['other_costs'] - 160000); // transport local au poids (500 kg sur 600)
        $this->assertEquals(34.9, $l[$cable]['margin']);

        // Coût de revient reporté sur les articles ; bon de commande en dollars
        $this->assertSame(2, $api()->postJson("/api/appro/imports/{$f['id']}/apply")->assertOk()->json('updated'));
        $this->assertEquals(19524, Product::find($cable)->purchase_price);
        $r = $api()->postJson("/api/appro/imports/{$f['id']}/order")->assertOk()->json();
        $po = PurchaseDocument::find($r['order_id']);
        $this->assertSame('USD', $po->currency);
        $this->assertEquals(600, $po->exchange_rate);
        $this->assertEquals(2000 + 1000, $po->total);
        $this->assertSame('ordered', $r['data']['status']);
        $api()->postJson("/api/appro/imports/{$f['id']}/order")->assertStatus(422);
        $api()->putJson("/api/appro/imports/{$f['id']}", ['status' => 'received'])->assertOk()->assertJsonPath('data.arrived_at', now()->toDateString());
        $this->assertStringStartsWith('%PDF', $api()->get("/api/appro/imports/{$f['id']}/pdf")->assertOk()->getContent());
        $this->assertStringContainsString('.xlsx', (string) $api()->get("/api/appro/imports/{$f['id']}/xlsx")->assertOk()->headers->get('content-disposition'));
    }

    public function test_spec_comparison_award_rentals_supplier_history_and_dashboard(): void
    {
        ['api' => $api, 'cn' => $cn, 'fr' => $fr, 'ci' => $ci, 'cable' => $cable, 'disj' => $disj] = $this->env();

        // Cahier des charges envoyé par e-mail (un fournisseur sans e-mail est signalé)
        $s = $api()->postJson('/api/appro/specs', ['title' => 'Électrification du bâtiment R+3 Cocody', 'site' => 'Cocody Riviera 3', 'deadline' => now()->addDays(10)->toDateString(),
            'requirements' => [['label' => 'Câble U-1000 R2V 3G2,5', 'value' => 'Norme NF C 32-321, rouleau 100 m', 'quantity' => 40, 'unit' => 'rouleau'], ['label' => 'Disjoncteur 20 A', 'value' => 'Courbe C, 6 kA', 'quantity' => 120]]])
            ->assertCreated()->json('data');
        $this->assertCount(7, $s['sections']);
        $this->assertStringStartsWith('%PDF', $api()->get("/api/appro/specs/{$s['id']}/pdf")->assertOk()->getContent());
        $sent = $api()->postJson("/api/appro/specs/{$s['id']}/send", ['partner_ids' => [$cn, $fr, $ci]])->assertOk()->json('data');
        $this->assertSame(2, $sent['sent']);
        $this->assertSame(['Électro Plateau'], $sent['skipped']);
        $this->assertCount(1, collect($this->mails->messages())->last()->getOriginalMessage()->getAttachments());

        // Comparatif : lignes reprises du cahier des charges, puis tarifs connus et offres saisies
        $cmp = $api()->postJson('/api/appro/comparisons', ['spec_id' => $s['id']])->assertCreated()->json('data');
        $this->assertSame('Électrification du bâtiment R+3 Cocody', $cmp['title']);
        $this->assertCount(2, $cmp['lines']);
        $cmp = $api()->putJson("/api/appro/comparisons/{$cmp['id']}", ['lines' => [
            ['key' => $cmp['lines'][0]['key'], 'product_id' => $cable, 'description' => 'Câble 3G2,5 rouleau 100 m', 'quantity' => 40],
            ['key' => $cmp['lines'][1]['key'], 'product_id' => $disj, 'description' => 'Disjoncteur 20 A courbe C', 'quantity' => 120],
        ]])->assertOk()->json('data');
        [$k1, $k2] = array_column($cmp['lines'], 'key');
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'supplier_prices', 'op' => 'upsert', 'record_id' => $this->uuid(),
            'data' => ['partner_id' => $ci, 'product_id' => $cable, 'price' => 24000, 'lead_days' => 2]]]])->assertOk();
        $cmp = $api()->postJson("/api/appro/comparisons/{$cmp['id']}/prefill")->assertOk()->json('data');
        $this->assertCount(1, $cmp['offers']);
        $this->assertEquals(24000, $cmp['offers'][0]['prices'][$k1]);
        $offers = $cmp['offers'];
        $offers[0]['prices'][$k2] = 2100;
        $offers[] = ['partner_id' => $fr, 'currency' => 'EUR', 'delay_days' => 21, 'prices' => [$k1 => 35, $k2 => 3], 'extra' => 150000, 'payment_terms' => '30 % à la commande'];
        $offers[] = ['partner_id' => $cn, 'currency' => 'USD', 'rate' => 600, 'delay_days' => 45, 'prices' => [$k1 => 30]]; // incomplète
        $cmp = $api()->putJson("/api/appro/comparisons/{$cmp['id']}", ['offers' => $offers])->assertOk()->json('data');
        $a = $cmp['analysis'];
        $tot = collect($a['offers'])->keyBy('key');
        $keys = array_column($cmp['offers'], 'key');
        $this->assertEquals(40 * 24000 + 120 * 2100, $tot[$keys[0]]['total']);
        $this->assertEquals(round(40 * 35 * 655.957 + 120 * 3 * 655.957 + 150000, 2), $tot[$keys[1]]['total']);
        $this->assertFalse($tot[$keys[2]]['complete']);
        $this->assertSame($keys[0], $a['cheapest']);
        $this->assertSame($keys[0], $a['fastest']);
        $this->assertSame($keys[2], $a['best_lines'][$k1]['offer']); // meilleur prix par ligne, même si l'offre est incomplète ou plus chère au total
        $this->assertSame($keys[1], $a['best_lines'][$k2]['offer']);
        $this->assertStringStartsWith('%PDF', $api()->get("/api/appro/comparisons/{$cmp['id']}/pdf")->assertOk()->getContent());

        // Attribution : bon de commande au fournisseur retenu, tarif fournisseur mis à jour
        $cmp = $api()->postJson("/api/appro/comparisons/{$cmp['id']}/award", ['offer' => $keys[0], 'note' => 'Moins cher et livré en 2 jours'])->assertOk()->json('data');
        $this->assertSame('awarded', $cmp['status']);
        $this->assertSame('Électro Plateau', $cmp['awarded_partner']);
        $po = PurchaseDocument::find($cmp['order_id']);
        $this->assertEquals((40 * 24000 + 120 * 2100) * 1.18, $po->total);
        $this->assertEquals(2100, SupplierPrice::where('partner_id', $ci)->where('product_id', $disj)->value('price'));
        $this->assertGreaterThan(0, $cmp['spread']);

        // Historique fournisseur : commande validée puis réceptionnée en 2 jours (dans le délai promis)
        $api()->postJson("/api/achats/documents/{$po->id}/validate")->assertOk();
        $wh = $api()->postJson('/api/stock/warehouses', ['name' => 'Dépôt', 'is_default' => true])->json('data.id');
        Carbon::setTestNow(now()->addDays(2));
        $lines = $po->fresh('lines')->lines;
        $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'purchase_receipts', 'op' => 'upsert', 'record_id' => $this->uuid(), 'data' => [
            'order_id' => $po->id, 'warehouse_id' => $wh, 'date' => now()->toDateString(), 'lines' => $lines->map(fn ($l) => ['order_line_id' => $l->id, 'quantity' => $l->quantity])->all()]]]])->assertOk();
        Carbon::setTestNow();
        $h = $api()->getJson("/api/appro/suppliers/$ci")->assertOk()->json('data');
        $this->assertSame(1, $h['kpis']['orders']);
        $this->assertEquals(2, $h['kpis']['lead_days']);
        $this->assertEquals(100, $h['kpis']['on_time']);
        $this->assertEquals(100, $h['kpis']['complete']);
        $this->assertEquals(5, $h['kpis']['score']);
        $this->assertEquals(24000, collect($h['products'])->firstWhere('product_id', $cable)['last_price']);
        $this->assertTrue($h['comparisons'][0]['won']);
        $this->assertSame('Électro Plateau', $api()->getJson('/api/appro/suppliers')->assertOk()->json('data.0.name'));
        $api()->get('/api/appro/suppliers/xlsx')->assertOk();

        // Location d'une nacelle : dépassement, prolongation, retour, facture
        $r = $api()->postJson('/api/appro/rentals', ['kind' => 'equipment', 'title' => 'Nacelle ciseaux 12 m', 'partner_id' => $ci, 'start_date' => now()->subDays(10)->toDateString(),
            'end_date' => now()->subDay()->toDateString(), 'rate' => 45000, 'rate_unit' => 'day', 'deposit' => 300000, 'site' => 'Cocody Riviera 3'])->assertCreated()->json('data');
        $this->assertSame('active', $r['status']);
        $this->assertTrue($r['overdue']);
        $this->artisan('erp:rentals')->assertExitCode(0);
        $this->assertTrue(AppNotification::where('title', 'Location dépassée : à rendre ou à prolonger')->exists());
        $api()->postJson("/api/appro/rentals/{$r['id']}/extend", ['end_date' => now()->subDays(5)->toDateString()])->assertStatus(422);
        $r = $api()->postJson("/api/appro/rentals/{$r['id']}/extend", ['end_date' => now()->addDays(2)->toDateString(), 'note' => 'Finitions plafonds'])->assertOk()->json('data');
        $this->assertFalse($r['overdue']);
        $api()->postJson("/api/appro/rentals/{$r['id']}/invoice")->assertStatus(422);
        $r = $api()->postJson("/api/appro/rentals/{$r['id']}/return", ['date' => now()->toDateString(), 'deposit_returned' => true])->assertOk()->json('data');
        $this->assertEquals(11, $r['days']);
        $this->assertEquals(495000, $r['amount']);
        $r = $api()->postJson("/api/appro/rentals/{$r['id']}/invoice")->assertOk()->json('data');
        $this->assertSame('closed', $r['status']);
        $inv = PurchaseDocument::find($r['invoice_id']);
        $this->assertSame('supplier_invoice', $inv->type);
        $this->assertSame('622', $inv->lines->first()->account_code);
        $this->assertEquals(495000 * 1.18, $inv->total);
        $this->assertSame(['invoice', 'return', 'extend', 'created'], array_column($r['events'], 'type'));
        $w = $api()->postJson('/api/appro/rentals', ['kind' => 'service', 'title' => 'Électricien intérimaire', 'start_date' => now()->addDays(3)->toDateString(), 'end_date' => now()->addDays(12)->toDateString(), 'rate' => 150000, 'rate_unit' => 'week'])->json('data');
        $this->assertSame('planned', $w['status']);
        $this->assertEquals(300000, $w['planned_amount']);
        $api()->get('/api/appro/rentals/xlsx')->assertOk();

        // Tableau de bord
        $d = $api()->getJson('/api/appro/dashboard')->assertOk()->json('data');
        $this->assertSame(0, $d['kpis']['comparisons_open']);
        $this->assertSame(0, $d['kpis']['rentals_overdue']);
        $this->assertGreaterThan(0, $d['kpis']['savings']);
        $this->assertSame('Électro Plateau', $d['suppliers'][0]['name']);
    }
}
