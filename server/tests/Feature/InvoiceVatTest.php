<?php

namespace Tests\Feature;

use App\Modules\Ventes\Models\SalesDocument;
use Tests\TestCase;

class InvoiceVatTest extends TestCase
{
    public function test_vat_settings_payment_method_and_receipt_box(): void
    {
        [$company, , $token] = $this->makeCompany('Akwaba');
        $push = function (array $data) use ($token) {
            $id = $this->uuid();
            $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $id,
                'data' => $data + ['type' => 'invoice', 'date' => now()->toDateString()]]]])->assertOk()->assertJsonPath('results.0.status', 'applied');

            return SalesDocument::withoutGlobalScopes()->with('lines')->find($id);
        };
        $line = ['id' => $this->uuid(), 'description' => 'Prestation', 'quantity' => 1, 'unit_price' => 55000, 'tax_rate' => 18];

        // Assujetti (par défaut) : TVA 18 %, mode de règlement et case décharge enregistrés
        $d = $push(['payment_method' => 'mobile_money', 'receipt_box' => true, 'lines' => [$line]]);
        $this->assertEquals(64900, $d->total);
        $this->assertSame('mobile_money', $d->payment_method);
        $this->assertTrue($d->receipt_box);

        // Mode de règlement inconnu : refusé
        $this->api($token)->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $this->uuid(),
            'data' => ['type' => 'invoice', 'date' => now()->toDateString(), 'payment_method' => 'bitcoin', 'lines' => [array_merge($line, ['id' => $this->uuid()])]]]]])->assertJsonPath('results.0.status', 'rejected');

        // Réglages de la société : pays, non assujetti, régime, case décharge
        $this->api($token)->putJson('/api/company', ['country' => 'SN', 'settings' => ['vat_subject' => false, 'tax_regime' => 'Entreprenant', 'invoice_receipt_box' => true, 'invoice_amount_words' => true]])->assertOk();
        $s = $company->fresh()->settings;
        $this->assertFalse($s['vat_subject']);
        $this->assertSame('Entreprenant', $s['tax_regime']);
        $this->assertSame('SN', $company->fresh()->country);

        // Non assujetti : la TVA envoyée est ignorée, total = HT
        $d = $push(['lines' => [array_merge($line, ['id' => $this->uuid()])]]);
        $this->assertEquals(55000, $d->total);
        $this->assertEquals(0, $d->tax_total);
        $this->assertEquals(0, $d->lines->first()->tax_rate);
    }
}
