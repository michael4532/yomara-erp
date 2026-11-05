<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Module;
use App\Models\ModuleOrder;
use App\Models\Plan;
use App\Models\PromoCode;
use Tests\TestCase;

class OffersPromoChatTest extends TestCase
{
    protected string $admin;

    protected string $client;

    protected int $companyId;

    protected int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        config(['erp.mode' => 'saas']);
        [, , $this->admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [$c, , $this->client] = $this->makeCompany('Boutique Awa', 'starter');
        $this->companyId = $c->id;
        Module::where('key', 'crm')->update(['billing_mode' => 'both', 'price_monthly' => 10000, 'price_yearly' => 100000, 'price_one_time' => 200000]);
        \App\Models\Plan::where('code', 'starter')->update(['modules' => ['ventes', 'stock']]);
        $this->bank = $this->api($this->admin)->postJson('/api/admin/payment-gateways', ['driver' => 'bank_transfer', 'label' => 'Virement', 'instructions' => 'RIB'])->assertCreated()->json('id');
    }

    public function test_plan_offers_free_plan_and_intro_and_yearly_discounts(): void
    {
        $free = Plan::where('code', 'gratuit')->first();
        $this->assertTrue($free->is_free);
        $starter = Plan::where('code', 'starter')->first();
        $this->assertEquals(15000 * 12 * .75, $starter->yearlyPrice());
        $this->assertEquals(12800, $starter->introPrice()); // 15 000 − 15 % arrondi à la centaine

        $store = $this->api($this->client)->getJson('/api/store')->assertOk()->json();
        $p = collect($store['plans'])->firstWhere('code', 'starter');
        $this->assertEquals(12800, $p['monthly_now']);
        $this->assertSame(3, $p['intro_left']);
        $this->assertTrue(collect($store['plans'])->firstWhere('code', 'gratuit')['is_free']);

        // Abonnement mensuel : remise de lancement, puis prolongation
        $o = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'plan:starter', 'billing' => 'monthly', 'gateway_id' => $this->bank])->assertCreated()->json('order');
        $this->assertEquals(12800, $o['amount']);
        $this->assertSame('new', $o['kind']);
        $this->api($this->admin)->postJson('/api/admin/store/orders/'.$o['id'].'/approve')->assertOk();
        $c = Company::find($this->companyId);
        $this->assertSame('active', $c->status);
        $this->assertTrue($c->subscription_ends_at->between(now()->addDays(27), now()->addDays(32)));
        $o2 = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'plan:starter', 'billing' => 'yearly', 'gateway_id' => $this->bank])->assertCreated()->json('order');
        $this->assertEquals(135000, $o2['amount']);
        $this->assertSame('renew', $o2['kind']);

        // Le super admin règle les remises
        $this->api($this->admin)->putJson('/api/admin/plans/'.$starter->id, ['yearly_discount' => 20, 'intro_discount' => 30, 'intro_months' => 2])->assertOk();
        $this->assertEquals(144000, Plan::find($starter->id)->price_yearly);

        // Passage gratuit immédiat
        $r = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'plan:gratuit', 'billing' => 'monthly'])->assertCreated()->json();
        $this->assertTrue($r['activated']);
        $this->assertSame($free->id, Company::find($this->companyId)->plan_id);
    }

    public function test_free_signup_and_expired_trial_falls_back_to_free_plan(): void
    {
        $this->post('/inscription', ['company_name' => 'Kiosque Ali', 'name' => 'Ali', 'email' => 'ali@ex.ci', 'password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1', 'plan' => 'gratuit', 'terms' => '1'])->assertRedirect();
        $c = Company::where('name', 'Kiosque Ali')->first();
        $this->assertSame('active', $c->status);
        $this->assertNull($c->trial_ends_at);

        Company::find($this->companyId)->update(['status' => 'trial', 'trial_ends_at' => now()->subDay(), 'subscription_ends_at' => null]);
        $this->api($this->client)->getJson('/api/bootstrap')->assertOk();
        $c = Company::find($this->companyId);
        $this->assertSame('active', $c->status);
        $this->assertSame('gratuit', $c->plan->code);
    }

    public function test_promo_codes_once_per_user_scoped_and_admin_batches(): void
    {
        $r = $this->api($this->admin)->postJson('/api/admin/promo-codes', ['code' => 'bienvenue25', 'value' => 25, 'applies_to' => 'all'])->assertCreated()->json('data.0');
        $this->assertSame('BIENVENUE25', $r['code']);
        $this->assertTrue(now()->addMonths(3)->isSameDay(\Carbon\Carbon::parse($r['valid_until'])));
        $this->api($this->admin)->postJson('/api/admin/promo-codes', ['value' => 5000, 'type' => 'amount', 'applies_to' => 'module', 'target' => 'crm', 'usage' => 'new', 'count' => 5, 'prefix' => 'CRM'])
            ->assertCreated()->assertJsonCount(5, 'data');
        $crmCode = PromoCode::where('target', 'crm')->first()->code;

        $preview = $this->api($this->client)->postJson('/api/store/promo', ['code' => 'bienvenue25', 'module' => 'crm', 'billing' => 'monthly'])->assertOk()->json();
        $this->assertEquals(2500, $preview['discount']);
        $this->api($this->client)->postJson('/api/store/promo', ['code' => $crmCode, 'module' => 'plan:starter', 'billing' => 'monthly'])->assertStatus(422);
        $this->api($this->client)->postJson('/api/store/promo', ['code' => 'NEXISTEPAS', 'module' => 'crm', 'billing' => 'monthly'])->assertStatus(422);

        $o = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $this->bank, 'promo_code' => $crmCode])->assertCreated()->json('order');
        $this->assertEquals(5000, $o['amount']);
        $this->assertEquals(5000, $o['discount']);
        // Déjà en cours d'utilisation par cet utilisateur
        $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'yearly', 'gateway_id' => $this->bank, 'promo_code' => $crmCode])->assertStatus(422);
        $this->api($this->admin)->postJson('/api/admin/store/orders/'.$o['id'].'/approve')->assertOk();
        $this->assertSame(1, PromoCode::where('code', $crmCode)->value('uses'));
        $this->api($this->client)->postJson('/api/store/promo', ['code' => $crmCode, 'module' => 'crm', 'billing' => 'monthly'])->assertStatus(422);

        // 100 % : activé sans paiement
        $this->api($this->admin)->postJson('/api/admin/promo-codes', ['code' => 'OFFERT', 'value' => 100])->assertCreated();
        Module::where('key', 'fne')->update(['billing_mode' => 'subscription', 'price_monthly' => 5000]);
        $r = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'fne', 'billing' => 'monthly', 'promo_code' => 'offert'])->assertCreated()->json();
        $this->assertTrue($r['activated']);
        $this->assertSame('paid', ModuleOrder::find($r['order']['id'])->status);

        // Expiré
        PromoCode::where('code', 'BIENVENUE25')->update(['valid_until' => now()->subDay()]);
        $this->api($this->client)->postJson('/api/store/promo', ['code' => 'BIENVENUE25', 'module' => 'crm', 'billing' => 'monthly'])->assertStatus(422)->assertJsonFragment(['message' => 'Ce code promo a expiré.']);

        $list = $this->api($this->admin)->getJson('/api/admin/promo-codes')->assertOk()->json();
        $this->assertSame(7, $list['stats']['total']);
        $this->api($this->client)->getJson('/api/admin/promo-codes')->assertForbidden();
    }

    public function test_live_chat_visitor_and_super_admin(): void
    {
        $r = $this->postJson('/api/livechat', ['name' => 'Moussa', 'email' => 'moussa@ex.ci', 'message' => 'Bonjour, le module paie gère la CNPS ?', 'page' => '/'])->assertCreated()->json();
        $token = $r['token'];
        $this->assertSame(48, strlen($token));

        $list = $this->api($this->admin)->getJson('/api/admin/livechat')->assertOk()->json();
        $this->assertSame(1, $list['unread']);
        $id = $list['data'][0]['id'];
        $this->api($this->admin)->postJson('/api/admin/livechat/'.$id, ['message' => 'Oui, CNPS et ITS sont calculés.'])->assertOk();
        $this->assertSame(0, $this->api($this->admin)->getJson('/api/admin/livechat')->json('unread'));

        $m = $this->getJson('/api/livechat/'.$token)->assertOk()->json('messages');
        $this->assertSame('admin', end($m)['from']);
        $this->postJson('/api/livechat/'.$token, ['message' => 'Merci !'])->assertOk();
        $this->getJson('/api/livechat/'.str_repeat('a', 48))->assertNotFound();
        $this->api($this->client)->getJson('/api/admin/livechat')->assertForbidden();
        $this->get('/')->assertOk()->assertSee('lcForm', false);
    }

    public function test_referral_rewards_and_credit(): void
    {
        \App\Models\Plan::where('code', 'gratuit')->update(['modules' => ['ventes']]); // le CRM s'achète à part ici
        $ref = $this->api($this->client)->getJson('/api/referral')->assertOk()->json();
        $this->assertStringContainsString('/inscription?parrain='.$ref['code'], $ref['link']);
        $this->get('/inscription?parrain='.$ref['code'])->assertOk()->assertSee('Invité par');

        $this->post('/inscription', ['company_name' => 'Filleul SARL', 'name' => 'Fatou', 'email' => 'fatou@ex.ci', 'password' => 'MotDePasse1', 'password_confirmation' => 'MotDePasse1', 'plan' => 'gratuit', 'terms' => '1', 'parrain' => strtolower($ref['code'])])->assertRedirect();
        $filleul = Company::where('name', 'Filleul SARL')->first();
        $this->assertSame($this->companyId, $filleul->referred_by);
        $token = \App\Models\User::where('email', 'fatou@ex.ci')->first()->createToken('t')->plainTextToken;

        // Premier achat du filleul : −10 % de bienvenue ; le parrain gagne 10 % du montant payé
        $o = $this->api($token)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $this->bank])->assertCreated()->json('order');
        $this->assertEquals(9000, $o['amount']);
        $this->api($this->admin)->postJson('/api/admin/store/orders/'.$o['id'].'/approve')->assertOk();
        $this->assertEquals(900, Company::find($this->companyId)->referral_credit);
        $sum = $this->api($this->client)->getJson('/api/referral')->json();
        $this->assertSame(1, $sum['referees']);
        $this->assertEquals(900, $sum['available']);

        // Le parrain utilise son crédit sur son prochain achat
        $o2 = $this->api($this->client)->postJson('/api/store/orders', ['module' => 'crm', 'billing' => 'monthly', 'gateway_id' => $this->bank])->assertCreated()->json('order');
        $this->assertEquals(9100, $o2['amount']);
        $this->assertEquals(900, $o2['credit_used']);
        $this->api($this->admin)->postJson('/api/admin/store/orders/'.$o2['id'].'/approve')->assertOk();
        $this->assertEquals(0, Company::find($this->companyId)->referral_credit);

        $this->api($this->admin)->putJson('/api/admin/referrals/settings', ['reward_percent' => 20])->assertOk();
        $adm = $this->api($this->admin)->getJson('/api/admin/referrals')->assertOk()->json();
        $this->assertEquals(20, $adm['settings']['reward_percent']);
        $this->assertSame('Boutique Awa', $adm['top'][0]['company']);

        // Inscription depuis l'application (mobile / ordinateur) : même parrainage, prospect et e-mail de bienvenue
        \Illuminate\Support\Facades\Mail::fake();
        $this->postJson('/api/auth/register', ['company_name' => 'Filleul App', 'name' => 'Ali', 'email' => 'ali@ex.ci', 'password' => 'MotDePasse1', 'phone' => '0700000000', 'referral' => $ref['code']])->assertCreated();
        $app = Company::where('name', 'Filleul App')->first();
        $this->assertSame($this->companyId, $app->referred_by);
        $this->assertTrue((bool) $app->plan?->is_free);
        $this->assertSame(2, $this->api($this->client)->getJson('/api/referral')->json('referees'));
    }

    public function test_installation_links_to_online_account_without_key(): void
    {
        $r = $this->postJson('/api/license/link', ['email' => \App\Models\User::where('company_id', $this->companyId)->value('email'), 'password' => 'secret123', 'fingerprint' => str_repeat('d', 64), 'device' => ['os' => 'Windows', 'company' => 'Boutique Awa Yopougon']])->assertOk()->json();
        $this->assertStringStartsWith('YMR-', $r['key']);
        $again = $this->postJson('/api/license/link', ['email' => \App\Models\User::where('company_id', $this->companyId)->value('email'), 'password' => 'secret123', 'fingerprint' => str_repeat('d', 64)])->json('key');
        $this->assertSame($r['key'], $again);
        $this->assertSame('windows', \App\Models\License::where('key', $r['key'])->value('target'));
        $this->postJson('/api/license/link', ['email' => 'inconnu@ex.ci', 'password' => 'x', 'fingerprint' => str_repeat('d', 64)])->assertStatus(422);
    }
}
