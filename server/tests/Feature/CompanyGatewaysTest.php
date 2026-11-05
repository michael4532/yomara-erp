<?php

namespace Tests\Feature;

use App\Models\PaymentTransaction;
use App\Modules\Ventes\Models\SalesDocument;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Moyens de paiement de la société : PayPal, lien marchand, test des clés. */
class CompanyGatewaysTest extends TestCase
{
    public function test_paypal_merchant_link_and_credentials_check(): void
    {
        [$company, , $token] = $this->makeCompany('Boutique Paiements');
        $api = fn () => $this->api($token);
        $drivers = collect($api()->getJson('/api/payments/setup')->assertOk()->json('drivers'))->pluck('driver');
        $this->assertTrue($drivers->contains('paypal'));
        $this->assertTrue($drivers->contains('merchant_link'));

        // lien marchand : https obligatoire, affiché sur la page de paiement
        $api()->postJson('/api/payments/gateways', ['driver' => 'merchant_link', 'label' => 'Wave', 'credentials' => ['url' => 'http://pay.wave.com/m/x']])->assertStatus(422);
        $ml = $api()->postJson('/api/payments/gateways', ['driver' => 'merchant_link', 'label' => 'Wave', 'credentials' => ['url' => 'https://pay.wave.com/m/M_abc123'], 'instructions' => 'Mettez le n° de facture en référence.'])
            ->assertCreated()->json();
        $api()->postJson('/api/payments/gateways/'.$ml['id'].'/test')->assertOk()->assertJsonPath('ok', true);

        // test des clés : refusées puis acceptées
        $ps = $api()->postJson('/api/payments/gateways', ['driver' => 'paystack', 'label' => 'Cartes', 'credentials' => ['secret_key' => 'sk_test_bad']])->assertCreated()->json();
        Http::fake(['api.paystack.co/balance' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);
        $api()->postJson('/api/payments/gateways/'.$ps['id'].'/test')->assertStatus(422)->assertJsonPath('ok', false);
        $this->assertStringContainsString('Invalid key', $api()->postJson('/api/payments/gateways/'.$ps['id'].'/test')->json('message'));

        // PayPal : conversion du franc CFA en euros, approbation, capture à la vérification
        $pp = $api()->postJson('/api/payments/gateways', ['driver' => 'paypal', 'label' => 'PayPal', 'mode' => 'test', 'credentials' => ['client_id' => 'cid', 'client_secret' => 'sec']])->assertCreated()->json();
        $client = $api()->postJson('/api/core/partners', ['name' => 'Diaspora Client', 'type' => 'customer', 'email' => 'd@x.fr'])->json('data.id');
        $res = $api()->postJson('/api/sync/push', ['mutations' => [['id' => $this->uuid(), 'entity' => 'sales_documents', 'op' => 'upsert', 'record_id' => $docId = $this->uuid(),
            'data' => ['type' => 'invoice', 'partner_id' => $client, 'date' => now()->toDateString(), 'status' => 'validated', 'lines' => [['id' => $this->uuid(), 'description' => 'Prestation', 'quantity' => 1, 'unit_price' => 655957, 'tax_rate' => 0, 'discount_percent' => 0]]]]]])->json('results.0');
        $this->assertSame('applied', $res['status']);
        $link = $api()->postJson('/api/payments/pay-link/'.$docId)->assertOk()->json();
        $url = $link['url'] ?? $link['data']['url'];
        $path = parse_url($url, PHP_URL_PATH);
        $this->flushHeaders();
        app()->forgetInstance('tenant');
        $this->get($path)->assertOk()->assertSee('Payer avec Wave')->assertSee('https://pay.wave.com/m/M_abc123', false)->assertSee('PayPal');

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok']),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response(['id' => 'ORDER1', 'status' => 'CREATED', 'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER1']]], 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER1' => Http::response(['id' => 'ORDER1', 'status' => 'APPROVED']),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER1/capture' => Http::response(['id' => 'ORDER1', 'status' => 'COMPLETED'], 201),
        ]);
        $this->post($path.'/'.$pp['id'])->assertRedirect('https://www.sandbox.paypal.com/checkoutnow?token=ORDER1');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/checkout/orders') && $r['purchase_units'][0]['amount'] == ['currency_code' => 'EUR', 'value' => '1000.00']);
        $tx = PaymentTransaction::withoutGlobalScopes()->latest('created_at')->first();
        $this->get('/payments/'.$tx->id.'/return');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/ORDER1/capture'));
        $this->assertSame('paid', SalesDocument::withoutGlobalScopes()->find($docId)->payment_status);
    }
}
