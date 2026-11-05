<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Liens de paiement libres et rapprochement : paiement en ligne → facture réglée sur le compte de trésorerie du prestataire. */
class PaymentLinksTest extends TestCase
{
    public function test_free_payment_link_creates_paid_invoice_on_treasury_account(): void
    {
        [$company, $user, $token] = $this->makeCompany('Liens SARL');
        $api = fn () => $this->api($token);
        $acc = $api()->postJson('/api/tresorerie/accounts', ['name' => 'Compte CinetPay', 'kind' => 'mobile_money'])->assertCreated()->json('data.id');
        $gw = $api()->postJson('/api/payments/gateways', ['driver' => 'cinetpay', 'label' => 'Mobile Money', 'mode' => 'live', 'credentials' => ['apikey' => 'k', 'site_id' => '1'], 'enabled' => true])->assertCreated()->json('id');
        $api()->putJson("/api/payments/gateways/$gw", ['treasury_account_id' => $acc])->assertOk()->assertJsonPath('treasury_account_id', $acc);
        $this->assertSame('Compte CinetPay', collect($api()->getJson('/api/payments/setup')->json('treasury_accounts'))->firstWhere('id', $acc)['name']);

        $status = 'PENDING';
        Http::fake([
            'api-checkout.cinetpay.com/v2/payment' => Http::response(['data' => ['payment_url' => 'https://checkout.cinetpay.com/p/abc', 'payment_token' => 't']]),
            'api-checkout.cinetpay.com/v2/payment/check' => function () use (&$status) {
                return Http::response(['data' => ['status' => $status]]);
            },
        ]);

        // Lien à montant libre (minimum 5 000), réutilisable
        $link = $api()->postJson('/api/payments/links', ['label' => 'Acompte formation Excel', 'min_amount' => 5000, 'reusable' => true])->assertCreated()->json('data');
        $this->assertStringContainsString('/pay/l/', $link['url']);
        $path = parse_url($link['url'], PHP_URL_PATH);
        $this->flushHeaders();
        $this->get($path)->assertOk()->assertSee('Acompte formation Excel')->assertSee('Mobile Money');
        $this->post($path.'/'.$gw, ['amount' => 1000])->assertRedirect($path); // sous le minimum
        $this->post($path.'/'.$gw, ['amount' => 25000, 'name' => 'Koffi Yao', 'phone' => '07 07 11 22 33', 'email' => 'koffi@gmail.com'])->assertRedirect('https://checkout.cinetpay.com/p/abc');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/payment') && $r['amount'] === 25000 && $r['customer_name'] === 'Koffi Yao');

        $tx = DB::table('payment_transactions')->where('purpose', 'pay_link')->first();
        $status = 'ACCEPTED';
        $this->postJson('/api/payments/'.$tx->id.'/notify')->assertOk();
        $l = DB::table('pay_links')->find($link['id']);
        $this->assertEquals(['open', 1, 25000.0], [$l->status, (int) $l->payments_count, (float) $l->collected]); // réutilisable : reste ouvert
        $inv = DB::table('trade_documents')->find($l->last_document_id);
        $this->assertEquals(['invoice', 'paid', 25000.0], [$inv->type, $inv->payment_status, (float) $inv->total]);
        $this->assertSame('Koffi Yao', DB::table('partners')->where('id', $inv->partner_id)->value('name'));
        $pay = DB::table('payments')->where('document_id', $inv->id)->first();
        $this->assertSame($acc, $pay->treasury_account_id);

        // Lien à montant fixe, à usage unique : payé puis inactif ; annulation d'un autre lien
        $fixed = $api()->postJson('/api/payments/links', ['label' => 'Commande Instagram robe wax', 'amount' => 15000, 'customer_name' => 'Awa'])->assertCreated()->json('data');
        $other = $api()->postJson('/api/payments/links', ['label' => 'Test', 'amount' => 100])->assertCreated()->json('data');
        $api()->postJson('/api/payments/links/'.$other['id'].'/cancel')->assertOk();
        $fpath = parse_url($fixed['url'], PHP_URL_PATH);
        $this->flushHeaders();
        $this->post($fpath.'/'.$gw, [])->assertRedirect('https://checkout.cinetpay.com/p/abc');
        $tx2 = DB::table('payment_transactions')->where('purpose', 'pay_link')->where('purpose_id', (string) $fixed['id'])->first();
        $this->postJson('/api/payments/'.$tx2->id.'/notify')->assertOk();
        $this->get($fpath)->assertOk()->assertSee('Paiement reçu');
        $this->get(parse_url($other['url'], PHP_URL_PATH))->assertSee('plus actif');
        $list = collect($this->api($token)->getJson('/api/payments/links')->assertOk()->json('data'))->keyBy('id');
        $this->assertEquals(['paid', 'cancelled', 'open'], [$list[$fixed['id']]['status'], $list[$other['id']]['status'], $list[$link['id']]['status']]);

        // Facture classique payée en ligne : aussi rapprochée sur le compte du prestataire
        $this->assertTrue(DB::table('app_notifications')->where('company_id', $company->id)->where('title', 'like', 'Paiement reçu%')->exists());
    }
}
