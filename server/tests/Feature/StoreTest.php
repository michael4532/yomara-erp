<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\PaymentGateway;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StoreTest extends TestCase
{
    protected string $admin;

    protected string $client;

    protected int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.mode' => 'saas']);
        // Ces tests achètent le CRM : on le sort du socle gratuit (sinon il est compris d'office dans toutes les formules)
        \App\Models\Plan::where('code', 'gratuit')->get()->each->update(['modules' => ['ventes']]);
        [, , $this->admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        \App\Models\Plan::where('code', 'starter')->update(['modules' => ['ventes', 'stock']]); // formule réduite pour ce test (CRM hors formule)
        [$c, , $this->client] = $this->makeCompany('Boutique Awa', 'starter'); // ventes + stock
        $this->companyId = $c->id;
        Module::where('key', 'crm')->update(['billing_mode' => 'both', 'price_monthly' => 7500, 'price_yearly' => 75000, 'price_one_time' => 150000, 'trial_days' => 14]);
    }

    protected function gateway(array $data): int
    {
        return $this->api($this->admin)->postJson('/api/admin/payment-gateways', $data)->assertCreated()->json('id');
    }

    protected function crm(): array
    {
        return collect($this->api($this->client)->getJson('/api/store')->assertOk()->json('modules'))->firstWhere('key', 'crm');
    }

    public function test_offline_order_validated_by_super_admin_then_renewed(): void
    {
        $bank = $this->gateway(['driver' => 'bank_transfer', 'label' => 'Virement SGCI', 'instructions' => "SGCI Plateau\nRIB CI008 01234 000123456789 12"]);
        $this->api($this->admin)->putJson('/api/admin/store/settings', ['contact' => ['name' => 'Yomara Technologies', 'phone' => '+225 07 00 00 00 00', 'whatsapp' => '+225 07 00 00 00 00']])->assertOk();

        $store = $this->api($this->client)->getJson('/api/store')->assertOk()->json();
        $this->assertSame('Yomara Technologies', $store['contact']['name']);
        $this->assertSame('Virement SGCI', $store['payment_methods'][0]['label']);
        $this->assertArrayNotHasKey('credentials', $store['payment_methods'][0]);
        $crm = collect($store['modules'])->firstWhere('key', 'crm');
        $this->assertFalse($crm['active']);
        $this->assertEquals(['monthly' => 7500, 'yearly' => 75000, 'one_time' => 150000], $crm['offers']);
        $this->assertTrue($crm['can_trial']);
        $this->api($this->client)->getJson('/api/crm/leads')->assertStatus(403);

        $r = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank])->assertCreated()->json();
        $this->assertNull($r['payment_url']);
        $this->assertStringContainsString('RIB', $r['instructions']);
        $this->assertSame('pending', $r['order']['status']);
        $this->assertMatchesRegularExpression('/^MOD-\d{4}-\d{5}$/', $r['order']['number']);
        $id = $r['order']['id'];

        $this->api($this->client)->postJson("/api/store/orders/$id/declare", ['reference' => 'VIR-889911'])->assertOk()->assertJsonPath('order.status', 'awaiting_validation');
        $this->assertSame(1, $this->api($this->admin)->getJson('/api/admin/store/orders')->json('to_validate'));
        $this->api($this->admin)->postJson("/api/admin/store/orders/$id/approve")->assertOk();

        $crm = $this->crm();
        $this->assertTrue($crm['active']);
        $this->assertSame('purchase', $crm['source']);
        $this->api($this->client)->getJson('/api/crm/leads')->assertOk();
        $first = \Carbon\Carbon::parse($crm['expires_at']);
        $this->assertTrue($first->between(now()->addMonthNoOverflow()->subDay(), now()->addMonthNoOverflow()->addDay()));

        // Pas de nouvelle commande tant que le module est actif ; renouvellement dans les 7 derniers jours
        $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank])->assertStatus(422);
        $this->travel(25)->days();
        // Renouvellement : prolongé à partir de l'échéance en cours
        $id2 = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank])->json('order.id');
        $this->api($this->admin)->postJson("/api/admin/store/orders/$id2/approve")->assertOk();
        $this->assertTrue(\Carbon\Carbon::parse($this->crm()['expires_at'])->gt($first->copy()->addWeeks(3)));

        // Facturation mensuelle de la société : formule + CRM
        $this->assertStringContainsString('CRM', json_encode($this->api($this->admin)->getJson('/api/admin/companies/'.$this->companyId)->json()));
    }

    public function test_online_payment_with_cinetpay_activates_module(): void
    {
        $gw = $this->gateway(['driver' => 'cinetpay', 'label' => 'Mobile Money / carte', 'mode' => 'test', 'credentials' => ['apikey' => 'KEY123456789', 'site_id' => '445566', 'secret_key' => 'S']]);
        // Les clés ne ressortent jamais en clair
        $settings = $this->api($this->admin)->getJson('/api/admin/store/settings')->json();
        $this->assertStringNotContainsString('KEY123456789', json_encode($settings));
        $this->assertSame('••••••6789', collect($settings['gateways'])->firstWhere('id', $gw)['credentials_masked']['apikey']);

        Http::fake([
            'api-checkout.cinetpay.com/v2/payment/check' => Http::sequence()
                ->push(['code' => '662', 'data' => ['status' => 'WAITING_CUSTOMER_PAYMENT']])
                ->push(['code' => '00', 'data' => ['status' => 'ACCEPTED']]),
            'api-checkout.cinetpay.com/v2/payment' => Http::response(['code' => '201', 'data' => ['payment_url' => 'https://checkout.cinetpay.com/payment/abc', 'payment_token' => 'tok']]),
        ]);
        $r = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'yearly', 'gateway_id' => $gw])->assertCreated()->json();
        $this->assertSame('https://checkout.cinetpay.com/payment/abc', $r['payment_url']);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/v2/payment') && $req['amount'] === 75000 && $req['site_id'] === '445566' && $req['currency'] === 'XOF');

        // Premier contrôle : en attente ; notification du prestataire : payé
        $this->api($this->client)->postJson('/api/store/orders/'.$r['order']['id'].'/verify')->assertOk()->assertJsonPath('order.status', 'pending');
        $this->postJson('/api/payments/'.$r['transaction'].'/notify')->assertOk();
        $this->assertTrue($this->crm()['active']);
        $this->assertSame('yearly', $this->crm()['billing']);

        // Notification répétée : sans effet (idempotent)
        $this->postJson('/api/payments/'.$r['transaction'].'/notify')->assertOk();
        $this->assertSame(1, \App\Models\ModuleOrder::where('status', 'paid')->count());

        // Page de retour navigateur
        $this->get('/payments/'.$r['transaction'].'/return')->assertOk()->assertSee('Paiement reçu');
    }

    public function test_wave_checkout_activates_module_by_webhook_or_background_check(): void
    {
        $gw = $this->gateway(['driver' => 'wave', 'label' => 'Wave', 'mode' => 'live', 'credentials' => ['api_key' => 'wave_ci_prod_abcdef']]);
        Http::fake([
            'api.wave.com/v1/checkout/sessions/*' => Http::sequence()
                ->push(['checkout_status' => 'open', 'payment_status' => 'processing'])
                ->push(['checkout_status' => 'complete', 'payment_status' => 'succeeded'])
                ->push(['checkout_status' => 'complete', 'payment_status' => 'succeeded']),
            'api.wave.com/v1/checkout/sessions' => Http::sequence()
                ->push(['id' => 'cos-1', 'wave_launch_url' => 'https://pay.wave.com/c/cos-1'])
                ->push(['id' => 'cos-2', 'wave_launch_url' => 'https://pay.wave.com/c/cos-2']),
        ]);
        $r = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $gw])->assertCreated()->json();
        $this->assertSame('https://pay.wave.com/c/cos-1', $r['payment_url']);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/checkout/sessions') && $req['amount'] === '7500' && $req['client_reference'] === $r['transaction']);

        // Webhook Wave (paiement encore en cours) puis vérification en tâche de fond : le module s'active sans retour du client
        $this->postJson('/api/payments/webhook/wave', ['type' => 'checkout.session.completed', 'data' => ['id' => 'cos-1', 'client_reference' => $r['transaction']]])->assertOk();
        $this->assertFalse($this->crm()['active']);
        $this->artisan('erp:payments-check')->assertSuccessful();
        $this->assertTrue($this->crm()['active']);
        $this->assertSame('paid', PaymentTransaction::find($r['transaction'])->status);

        // Un webhook d'un autre prestataire ne touche pas une transaction Wave
        $this->postJson('/api/payments/webhook/stripe', ['data' => ['client_reference' => $r['transaction']]])->assertOk();
    }

    public function test_payment_with_wrong_amount_or_reference_never_activates(): void
    {
        $gw = $this->gateway(['driver' => 'wave', 'label' => 'Wave', 'mode' => 'live', 'credentials' => ['api_key' => 'wave_ci_prod_abcdef']]);
        Http::fake([
            'api.wave.com/v1/checkout/sessions/*' => function () {
                static $n = 0;
                $n++;

                return Http::response(match ($n) {
                    // Montant payé inférieur au prix : refusé
                    1 => ['checkout_status' => 'complete', 'payment_status' => 'succeeded', 'amount' => '100', 'currency' => 'XOF'],
                    // Montant juste mais pour une autre transaction : refusé
                    2 => ['checkout_status' => 'complete', 'payment_status' => 'succeeded', 'amount' => '7500', 'currency' => 'XOF', 'client_reference' => 'autre-transaction'],
                    // Bon montant, bonne devise, bonne référence : accepté
                    default => ['checkout_status' => 'complete', 'payment_status' => 'succeeded', 'amount' => '7500', 'currency' => 'XOF', 'client_reference' => $GLOBALS['__tx']],
                });
            },
            'api.wave.com/v1/checkout/sessions' => Http::sequence()
                ->push(['id' => 'cos-a', 'wave_launch_url' => 'https://pay.wave.com/c/a'])
                ->push(['id' => 'cos-b', 'wave_launch_url' => 'https://pay.wave.com/c/b'])
                ->push(['id' => 'cos-c', 'wave_launch_url' => 'https://pay.wave.com/c/c']),
        ]);
        foreach (['failed', 'failed', 'paid'] as $expected) {
            $r = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $gw])->assertCreated()->json();
            $GLOBALS['__tx'] = $r['transaction'];
            $this->postJson('/api/payments/'.$r['transaction'].'/notify')->assertOk();
            $this->assertSame($expected, PaymentTransaction::find($r['transaction'])->status);
            $this->assertSame($expected === 'paid', $this->crm()['active']);
            // Commande en ligne : une référence « déclarée » par le client n'est pas acceptée
            if ($expected === 'failed') {
                $this->api($this->client)->postJson('/api/store/orders/'.$r['order']['id'].'/declare', ['reference' => 'FAUX-123'])->assertStatus(422);
            }
        }
        // Inscription sur une formule inactive : refusée
        \App\Models\Plan::where('code', 'entreprise')->update(['is_active' => false]);
        $this->postJson('/api/auth/register', ['company_name' => 'Pirate', 'name' => 'P', 'email' => 'p@pirate.ci', 'password' => 'secret123', 'plan' => 'entreprise'])->assertStatus(422)->assertJsonValidationErrors('plan');
    }

    public function test_department_pack_bought_in_one_payment(): void
    {
        $bank = $this->gateway(['driver' => 'bank_transfer', 'label' => 'Virement']);
        $packs = collect($this->api($this->client)->getJson('/api/store')->assertOk()->json('packs'))->keyBy('code');
        // Pack Restaurant : ventes et stock déjà dans la formule → seuls caisse, restaurant et fidélité sont facturés, −20 %
        $resto = $packs['restaurant'];
        $this->assertEqualsCanonicalizing(['pos', 'restaurant', 'fidelite'], $resto['to_buy']);
        $this->assertSame(15000.0, (float) $resto['full_monthly']);
        $this->assertSame([12000.0, 120000.0], [(float) $resto['offers']['monthly'], (float) $resto['offers']['yearly']]);
        $this->assertSame(round(30000 * 0.75 / 100) * 100, (float) $packs['rh']['offers']['monthly']); // 6 modules RH à −25 %

        $this->api($this->client)->postJson('/api/store/orders', ['module' => 'pack:restaurant', 'billing' => 'one_time', 'gateway_id' => $bank])->assertStatus(422);
        $r = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'pack:restaurant', 'billing' => 'yearly', 'gateway_id' => $bank])->assertCreated()->json('order');
        $this->assertSame(['Pack Restaurant', true, 120000.0], [$r['module_name'], $r['is_pack'], (float) $r['amount']]);
        $this->api($this->admin)->postJson('/api/admin/store/orders/'.$r['id'].'/approve')->assertOk();

        $store = $this->api($this->client)->getJson('/api/store')->json();
        $mods = collect($store['modules'])->keyBy('key');
        foreach (['pos', 'restaurant', 'fidelite'] as $k) {
            $this->assertSame([true, 'purchase', 'yearly'], [$mods[$k]['active'], $mods[$k]['source'], $mods[$k]['billing']], $k);
        }
        $this->assertTrue(collect($store['packs'])->firstWhere('code', 'restaurant')['active']);
        $this->api($this->client)->postJson('/api/store/orders', ['module' => 'pack:inconnu', 'billing' => 'monthly', 'gateway_id' => $bank])->assertStatus(422);
    }

    public function test_trial_one_time_purchase_and_dependencies(): void
    {
        $bank = $this->gateway(['driver' => 'bank_transfer', 'label' => 'Virement']);
        $this->api($this->client)->postJson('/api/store/trial/crm')->assertOk();
        $crm = $this->crm();
        $this->assertTrue($crm['active']);
        $this->assertSame('trial', $crm['source']);
        $this->assertFalse($crm['can_trial']);
        $this->api($this->client)->postJson('/api/store/trial/crm')->assertStatus(422);

        // Achat définitif : sans échéance, et non rachetable
        $id = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'one_time', 'gateway_id' => $bank])->json('order.id');
        $this->api($this->admin)->postJson("/api/admin/store/orders/$id/approve")->assertOk();
        $this->assertNull($this->crm()['expires_at']);
        $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'one_time', 'gateway_id' => $bank])->assertStatus(422);

        // La paie nécessite le module RH
        Module::where('key', 'paie')->update(['billing_mode' => 'subscription', 'price_monthly' => 15000]);
        $this->api($this->client)->postJson('/api/store/orders', ['module' => 'paie', 'billing' => 'monthly', 'gateway_id' => $bank])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Ce module nécessite d\'abord : Ressources humaines.']);
        // Formule non proposée
        Module::where('key', 'rh')->update(['billing_mode' => 'subscription', 'price_monthly' => 10000, 'price_yearly' => 0]);
        $this->api($this->client)->postJson('/api/store/orders', ['module' => 'rh', 'billing' => 'one_time', 'gateway_id' => $bank])->assertStatus(422);

        // Un module gratuit est actif pour tout le monde
        Module::where('key', 'rh')->update(['billing_mode' => 'free']);
        $this->assertTrue(collect($this->api($this->client)->getJson('/api/store')->json('modules'))->firstWhere('key', 'rh')['active']);

        // Seul un administrateur de la société peut acheter
        $roles = collect($this->api($this->client)->getJson('/api/roles')->json('data'))->keyBy('name');
        $this->api($this->client)->postJson('/api/users', ['name' => 'Caisse', 'email' => 'caisse@awa.ci', 'password' => 'secret123', 'role_id' => $roles['Caissier']['id']])->assertCreated();
        $t = \App\Models\User::where('email', 'caisse@awa.ci')->first()->createToken('t')->plainTextToken;
        $this->api($t)->getJson('/api/store')->assertOk()->assertJsonPath('can_buy', false);
        $this->api($t)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $bank])->assertStatus(403);
    }

    public function test_company_collects_invoice_online_with_its_own_gateway(): void
    {
        // Le super admin limite les prestataires proposés aux sociétés
        $this->api($this->admin)->putJson('/api/admin/store/settings', ['company_drivers' => ['paystack', 'wave', 'bank_transfer']])->assertOk();
        $this->api($this->client)->postJson('/api/payments/gateways', ['driver' => 'stripe', 'label' => 'Stripe', 'credentials' => ['secret_key' => 'x']])->assertStatus(422);
        $gw = $this->api($this->client)->postJson('/api/payments/gateways', ['driver' => 'paystack', 'label' => 'Carte bancaire', 'credentials' => ['secret_key' => 'sk_test_abc123456']])->assertCreated()->json('id');
        // Mise à jour sans ressaisir la clé : conservée
        $this->api($this->client)->putJson("/api/payments/gateways/$gw", ['label' => 'Carte / Mobile Money', 'credentials' => ['secret_key' => '']])->assertOk();
        $this->assertSame('sk_test_abc123456', PaymentGateway::find($gw)->credential('secret_key'));

        $this->api($this->client)->putJson('/api/payments/bank-accounts', ['bank_accounts' => [['bank' => 'Ecobank CI', 'holder' => 'Boutique Awa', 'account' => 'CI059 01001 121212121212 45']], 'payment_instructions' => 'Merci d\'indiquer le numéro de facture.'])->assertOk();

        $partner = $this->api($this->client)->postJson('/api/core/partners', ['name' => 'Hôtel du Lac', 'email' => 'compta@hoteldulac.ci'])->json('data.id');
        $doc = $this->api($this->client)->postJson('/api/ventes/documents', [
            'type' => 'invoice', 'partner_id' => $partner, 'date' => now()->toDateString(),
            'lines' => [['description' => 'Fournitures', 'quantity' => 1, 'unit_price' => 100000, 'tax_rate' => 18]],
        ])->json('data');
        $this->api($this->client)->postJson('/api/payments/pay-link/'.$doc['id'])->assertStatus(422); // brouillon
        $this->api($this->client)->postJson("/api/ventes/documents/{$doc['id']}/validate")->assertOk();
        $link = $this->api($this->client)->postJson('/api/payments/pay-link/'.$doc['id'])->assertOk()->json();
        $this->assertSame(1, $link['online_methods']);
        $path = parse_url($link['url'], PHP_URL_PATH);

        $this->get($path)->assertOk()->assertSee('118 000')->assertSee('Carte / Mobile Money')->assertSee('Ecobank CI');

        Http::fake([
            'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/xyz', 'reference' => 'ref-1']]),
            'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success']]),
        ]);
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
            ->post($path.'/'.$gw)->assertRedirect('https://checkout.paystack.com/xyz');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'initialize') && $r['amount'] === 11800000 && $r['email'] === 'compta@hoteldulac.ci');

        $tx = PaymentTransaction::where('purpose', 'invoice')->first();
        $this->get('/payments/'.$tx->id.'/return')->assertOk()->assertSee('Paiement reçu');

        $d = $this->api($this->client)->getJson('/api/ventes/documents/'.$doc['id'])->json('data');
        $this->assertSame('paid', $d['payment_status']);
        $this->get($path)->assertSee('Facture réglée');
    }

    public function test_gateway_drivers_speak_each_provider_api(): void
    {
        $tx = fn ($amount = 5000) => PaymentTransaction::create(['gateway_id' => 1, 'purpose' => 'x', 'purpose_id' => '1', 'amount' => $amount, 'currency' => 'XOF']);
        $ctx = new \App\Payments\PaymentContext('Test', 'https://erp.ci/r', 'https://erp.ci/n', 'https://erp.ci/c', 'Awa', 'awa@test.ci');
        $make = fn (string $driver, array $cred, string $mode = 'live') => \App\Payments\GatewayRegistry::make(new PaymentGateway(['driver' => $driver, 'label' => $driver, 'mode' => $mode, 'credentials' => $cred]));

        Http::fake([
            'app.paydunya.com/sandbox-api/v1/checkout-invoice/create' => Http::response(['response_code' => '00', 'response_text' => 'https://paydunya.com/sandbox-checkout/invoice/tk1', 'token' => 'tk1']),
            'app.paydunya.com/sandbox-api/v1/checkout-invoice/confirm/tk1' => Http::response(['response_code' => '00', 'status' => 'completed']),
            'sandbox-api.fedapay.com/v1/transactions' => Http::response(['v1/transaction' => ['id' => 987, 'status' => 'pending']]),
            'sandbox-api.fedapay.com/v1/transactions/987/token' => Http::response(['token' => 't', 'url' => 'https://process.fedapay.com/t']),
            'sandbox-api.fedapay.com/v1/transactions/987' => Http::response(['v1/transaction' => ['id' => 987, 'status' => 'approved']]),
            'api.wave.com/v1/checkout/sessions' => Http::response(['id' => 'cos-1', 'wave_launch_url' => 'https://pay.wave.com/c/cos-1']),
            'api.wave.com/v1/checkout/sessions/cos-1' => Http::response(['id' => 'cos-1', 'payment_status' => 'succeeded', 'checkout_status' => 'complete']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/cs_1']),
            'api.stripe.com/v1/checkout/sessions/cs_1' => Http::response(['id' => 'cs_1', 'payment_status' => 'paid']),
            'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/x']]),
            'api.flutterwave.com/v3/transactions/verify_by_reference*' => Http::response(['status' => 'success', 'data' => ['status' => 'successful', 'amount' => 5000]]),
        ]);

        foreach ([
            ['paydunya', ['master_key' => 'm', 'private_key' => 'p', 'token' => 't'], 'test', 'https://paydunya.com/sandbox-checkout/invoice/tk1'],
            ['fedapay', ['secret_key' => 'sk_sandbox'], 'test', 'https://process.fedapay.com/t'],
            ['wave', ['api_key' => 'wave_sn_prod'], 'live', 'https://pay.wave.com/c/cos-1'],
            ['stripe', ['secret_key' => 'sk_test'], 'live', 'https://checkout.stripe.com/cs_1'],
            ['flutterwave', ['secret_key' => 'FLWSECK'], 'live', 'https://checkout.flutterwave.com/x'],
        ] as [$driver, $cred, $mode, $url]) {
            $t = $tx();
            $g = $make($driver, $cred, $mode);
            $r = $g->initiate($t, $ctx);
            $this->assertSame($url, $r['url'], $driver);
            $t->update(['reference' => $r['reference']]);
            $this->assertSame('paid', $g->verify($t), $driver);
        }
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.wave.com') && $r->method() === 'POST' && $r['amount'] === '5000' && $r['currency'] === 'XOF');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'stripe.com') && $r->method() === 'POST' && $r['line_items[0][price_data][unit_amount]'] === 5000);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'paydunya') && $r->hasHeader('PAYDUNYA-MASTER-KEY', 'm'));

        // Clé manquante : message clair
        $this->expectException(\App\Payments\PaymentException::class);
        $make('wave', [])->initiate($tx(), $ctx);
    }

    public function test_regional_gateways_moneroo_notchpay_paygate_kkiapay(): void
    {
        $tx = fn ($amount = 5000) => PaymentTransaction::create(['gateway_id' => 1, 'purpose' => 'x', 'purpose_id' => '1', 'amount' => $amount, 'currency' => 'XOF']);
        $ctx = new \App\Payments\PaymentContext('Pack RH', 'https://erp.ci/r', 'https://erp.ci/n', 'https://erp.ci/c', 'Awa Koné', 'awa@test.ci', '+225 07 00 00 00 01');
        $make = fn (string $driver, array $cred, string $mode = 'live') => \App\Payments\GatewayRegistry::make(new PaymentGateway(['driver' => $driver, 'label' => $driver, 'mode' => $mode, 'credentials' => $cred]));
        Http::fake([
            'api.moneroo.io/v1/payments/initialize' => Http::response(['message' => 'ok', 'data' => ['id' => 'py_1', 'checkout_url' => 'https://checkout.moneroo.io/py_1']], 201),
            'api.moneroo.io/v1/payments/py_1/verify' => Http::response(['data' => ['id' => 'py_1', 'status' => 'success']]),
            'api.notchpay.co/payments/trx.np1' => Http::response(['transaction' => ['reference' => 'trx.np1', 'status' => 'complete']]),
            'api.notchpay.co/payments' => Http::response(['status' => 'Accepted', 'authorization_url' => 'https://pay.notchpay.co/trx.np1', 'transaction' => ['reference' => 'trx.np1']], 201),
            'paygateglobal.com/api/v2/status' => Http::response(['tx_reference' => 1234, 'status' => 0]),
            'api-sandbox.kkiapay.me/api/v1/transactions/status' => Http::sequence()
                ->push(['status' => 'SUCCESS', 'amount' => 5000, 'transactionId' => 'kk1'])
                ->push(['status' => 'SUCCESS', 'amount' => 100, 'transactionId' => 'kk2']),
        ]);

        foreach ([['moneroo', ['secret_key' => 'pvk_test'], 'https://checkout.moneroo.io/py_1'], ['notchpay', ['public_key' => 'pk_test_1'], 'https://pay.notchpay.co/trx.np1']] as [$driver, $cred, $url]) {
            $t = $tx();
            $g = $make($driver, $cred);
            $r = $g->initiate($t, $ctx);
            $this->assertSame($url, $r['url'], $driver);
            $t->update(['reference' => $r['reference']]);
            $this->assertSame('paid', $g->verify($t), $driver);
        }
        Http::assertSent(fn ($r) => str_contains($r->url(), 'moneroo') && $r->method() === 'POST' && $r['amount'] === 5000 && $r['customer']['last_name'] === 'Koné' && $r['metadata']['tx'] !== '');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'notchpay') && $r->method() === 'POST' && $r->hasHeader('Authorization', 'pk_test_1') && $r['reference'] !== '');

        // PayGate : page hébergée, référence = notre identifiant, statut 0 = payé
        $t = $tx();
        $g = $make('paygate', ['auth_token' => 'pg-token']);
        $r = $g->initiate($t, $ctx);
        $this->assertStringStartsWith('https://paygateglobal.com/api/v1/page?', $r['url']);
        $this->assertStringContainsString('identifier='.$t->id, $r['url']);
        $this->assertStringContainsString('amount=5000', $r['url']);
        $t->update(['reference' => $r['reference']]);
        $this->assertSame('paid', $g->verify($t));

        // KKiaPay : notre page ouvre le widget, l'identifiant reçu au retour est vérifié (montant compris)
        $gw = PaymentGateway::create(['driver' => 'kkiapay', 'label' => 'KKiaPay', 'mode' => 'test', 'enabled' => true, 'credentials' => ['public_key' => 'pub', 'private_key' => 'priv', 'secret_key' => 'sec']]);
        $t = PaymentTransaction::create(['gateway_id' => $gw->id, 'purpose' => 'x', 'purpose_id' => '1', 'amount' => 5000, 'currency' => 'XOF', 'status' => 'pending']);
        $r = \App\Payments\GatewayRegistry::make($gw)->initiate($t, $ctx);
        $t->update(['reference' => $r['reference'], 'meta' => $r['meta']]);
        $this->get($r['url'])->assertOk()->assertSee('openKkiapayWidget', false)->assertSee('"pub"', false);
        $this->artisan('erp:payments-check')->assertSuccessful(); // sans identifiant : reste en attente, sans appel
        $this->assertSame('pending', $t->fresh()->status);
        $this->get('/payments/'.$t->id.'/return?transaction_id=kk1')->assertOk();
        $this->assertSame(['paid', 'kk1'], [$t->fresh()->status, $t->fresh()->reference]);
        // Un identifiant déjà utilisé ou d'un autre montant ne règle pas un autre paiement
        $t2 = PaymentTransaction::create(['gateway_id' => $gw->id, 'purpose' => 'x', 'purpose_id' => '2', 'amount' => 5000, 'currency' => 'XOF', 'status' => 'pending', 'reference' => 'kkiapay-pending']);
        $this->get('/payments/'.$t2->id.'/return?transaction_id=kk1')->assertOk();
        $this->assertSame(['pending', 'kkiapay-pending'], [$t2->fresh()->status, $t2->fresh()->reference]);
        $this->postJson('/api/payments/webhook/kkiapay', ['transactionId' => 'kk2', 'partnerId' => $t2->id, 'event' => 'transaction.success'])->assertOk();
        $this->assertSame('failed', $t2->fresh()->status);

        // Adresse de notification affichée pour les prestataires à webhook unique
        $drivers = collect(\App\Payments\GatewayRegistry::catalog())->keyBy('driver');
        $this->assertStringEndsWith('/api/payments/webhook/moneroo', $drivers['moneroo']['webhook_url']);
        $this->assertArrayNotHasKey('webhook_url', $drivers['cinetpay']);
    }
}
