<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Audit : paiements CinetPay, WhatsApp, Shopify, rapprochement bancaire (cloisonnement entre sociétés, SSRF). */
class AuditPaiementsIntegrationsTest extends TestCase
{
    public function test_cinetpay_amount_rounded_to_five_is_accepted_but_short_payment_refused(): void
    {
        [, , $token] = $this->makeCompany('Arrondi SARL');
        $api = fn () => $this->api($token);
        $gw = $api()->postJson('/api/payments/gateways', ['driver' => 'cinetpay', 'label' => 'Mobile Money', 'mode' => 'live', 'credentials' => ['apikey' => 'k', 'site_id' => '1'], 'enabled' => true])->assertCreated()->json('id');
        $paid = 1005;
        Http::fake([
            'api-checkout.cinetpay.com/v2/payment' => Http::response(['data' => ['payment_url' => 'https://checkout.cinetpay.com/p/abc', 'payment_token' => 't']]),
            'api-checkout.cinetpay.com/v2/payment/check' => function () use (&$paid) {
                return Http::response(['data' => ['status' => 'ACCEPTED', 'amount' => (string) $paid, 'currency' => 'XOF']]);
            },
        ]);
        $link = $api()->postJson('/api/payments/links', ['label' => 'Acompte', 'amount' => 1002])->assertCreated()->json('data');
        $path = parse_url($link['url'], PHP_URL_PATH);
        $this->flushHeaders();
        $this->post($path.'/'.$gw, [])->assertRedirect('https://checkout.cinetpay.com/p/abc');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v2/payment') && $r['amount'] === 1005);
        $tx = DB::table('payment_transactions')->where('purpose', 'pay_link')->first();
        $this->postJson('/api/payments/'.$tx->id.'/notify')->assertOk();
        $this->assertSame('paid', DB::table('payment_transactions')->where('id', $tx->id)->value('status'));

        // Montant payé inférieur : refusé
        $link2 = $this->api($token)->postJson('/api/payments/links', ['label' => 'Solde', 'amount' => 20000])->assertCreated()->json('data');
        $this->flushHeaders();
        $this->post(parse_url($link2['url'], PHP_URL_PATH).'/'.$gw, [])->assertRedirect();
        $tx2 = DB::table('payment_transactions')->where('purpose', 'pay_link')->where('purpose_id', (string) $link2['id'])->first();
        $paid = 100;
        $this->postJson('/api/payments/'.$tx2->id.'/notify')->assertOk();
        $this->assertSame('failed', DB::table('payment_transactions')->where('id', $tx2->id)->value('status'));
    }

    public function test_whatsapp_conversation_cannot_reference_another_company(): void
    {
        [$other] = $this->makeCompany('Autre WA');
        DB::table('partners')->insert(['id' => $foreign = (string) Str::uuid(), 'company_id' => $other->id, 'type' => 'customer', 'name' => 'Client secret', 'email' => 'secret@autre.ci',
            'phone' => '0101010101', 'created_at' => now(), 'updated_at' => now(), 'version' => 1]);
        $foreignUser = User::where('company_id', $other->id)->value('id');
        [$company, , $token] = $this->makeCompany('Wa Audit');
        $conv = (new \App\Modules\Whatsapp\Models\Conversation)->forceFill(['company_id' => $company->id, 'phone' => '2250707000000', 'status' => 'open']);
        $conv->save();

        $this->api($token)->postJson('/api/whatsapp/conversations', ['phone' => '0707000001', 'body' => 'Bonjour', 'partner_id' => $foreign])->assertStatus(422)->assertJsonValidationErrors('partner_id');
        $this->api($token)->putJson('/api/whatsapp/conversations/'.$conv->id, ['partner_id' => $foreign])->assertStatus(422)->assertJsonValidationErrors('partner_id');
        $this->api($token)->putJson('/api/whatsapp/conversations/'.$conv->id, ['assigned_to' => $foreignUser])->assertStatus(422)->assertJsonValidationErrors('assigned_to');
        // Même relié en base, la fiche d'une autre société n'est jamais affichée
        $conv->forceFill(['partner_id' => $foreign])->save();
        $show = $this->api($token)->getJson('/api/whatsapp/conversations/'.$conv->id)->assertOk();
        $this->assertNull($show->json('partner'));
        $this->assertStringNotContainsString('secret@autre.ci', $show->getContent());
    }

    public function test_shopify_refuses_internal_addresses_and_foreign_pagination_links(): void
    {
        [, , $token] = $this->makeCompany('Shop Audit');
        Http::fake(['maboutique.myshopify.com/*' => Http::response(['products' => [['id' => 1]]], 200, ['Link' => '<https://evil.example/steal?page_info=x>; rel="next"'])]);
        $client = new \App\Modules\Shopify\ShopifyClient('maboutique.myshopify.com', 'shpat_secret');
        try {
            $client->all('products.json', 'products');
            $this->fail('Lien de pagination vers un autre hôte accepté.');
        } catch (\App\Modules\Woocommerce\WooException) {
        }
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'evil.example'));
        Http::fake(['*' => Http::response(['shop' => ['name' => 'X']])]);
        foreach (['127.0.0.1', '169.254.169.254', 'http://10.0.0.5:8080'] as $url) {
            $this->api($token)->postJson('/api/shopify/stores', ['url' => $url, 'consumer_key' => 'shpat_x', 'consumer_secret' => 'sec'])->assertStatus(422);
        }
        Http::assertNotSent(fn ($r) => ! str_contains($r->url(), 'maboutique'));
    }

    public function test_bank_rules_and_statement_matching_stay_within_company(): void
    {
        [$other, , $otherToken] = $this->makeCompany('Banque Autre');
        $oacc = $this->api($otherToken)->postJson('/api/tresorerie/accounts', ['name' => 'Banque B', 'kind' => 'bank'])->assertCreated()->json('data.id');
        $rule = $this->api($otherToken)->postJson('/api/tresorerie/bank/rules', ['name' => 'Frais secrets', 'contains' => 'AGIOS'])->assertCreated()->json('data.id');
        $oop = $this->api($otherToken)->postJson('/api/tresorerie/operations', ['account_id' => $oacc, 'date' => now()->toDateString(), 'amount' => -5000, 'label' => 'Loyer', 'category' => 'rent'])->json('data.id');

        [$company, , $token] = $this->makeCompany('Banque Audit');
        $api = fn () => $this->api($token);
        $acc = $api()->postJson('/api/tresorerie/accounts', ['name' => 'Banque A', 'kind' => 'bank'])->assertCreated()->json('data.id');
        $api()->putJson('/api/tresorerie/bank/rules/'.$rule, ['name' => 'Pirate', 'contains' => 'X'])->assertNotFound();
        $this->assertSame('Frais secrets', DB::table('tr_bank_rules')->where('id', $rule)->value('name'));
        $api()->postJson('/api/tresorerie/bank/rules', ['name' => 'R', 'contains' => 'X', 'account_id' => $oacc])->assertStatus(422);

        $sid = DB::table('tr_statements')->insertGetId(['company_id' => $company->id, 'account_id' => $acc, 'filename' => 'releve.csv', 'created_at' => now(), 'updated_at' => now()]);
        $line = DB::table('tr_statement_lines')->insertGetId(['company_id' => $company->id, 'account_id' => $acc, 'statement_id' => $sid, 'date' => now()->toDateString(), 'label' => 'LOYER',
            'amount' => -5000, 'hash' => Str::random(20), 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNotNull($oop);
        $api()->postJson('/api/tresorerie/statement-lines/'.$line, ['action' => 'match', 'type' => 'operation', 'id' => $oop])->assertStatus(422);
        $api()->postJson('/api/tresorerie/statement-lines/'.$line, ['action' => 'match', 'type' => 'operation', 'id' => (string) Str::uuid()])->assertStatus(422);
        $this->assertSame('open', DB::table('tr_statement_lines')->where('id', $line)->value('status'));
        // Mouvement du compte : accepté
        $op = $api()->postJson('/api/tresorerie/operations', ['account_id' => $acc, 'date' => now()->toDateString(), 'amount' => -5000, 'label' => 'Loyer', 'category' => 'rent'])->json('data.id');
        $api()->postJson('/api/tresorerie/statement-lines/'.$line, ['action' => 'match', 'type' => 'operation', 'id' => $op])->assertOk()->assertJsonPath('data.status', 'matched');
    }
}
