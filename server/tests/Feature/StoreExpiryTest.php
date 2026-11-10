<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Models\PaymentGateway;
use Carbon\Carbon;
use Tests\TestCase;

class StoreExpiryTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_default_prices_platform_gateways_expiry_reminders_and_renewal_banner(): void
    {
        config(['erp.mode' => 'saas']);
        [$company, $admin, $token] = $this->makeCompany('Boutique Awa', 'starter');
        // Tarif unique par défaut et moyens de paiement de la plateforme (en ligne à configurer, hors ligne actifs)
        $paid = Module::where('is_core', false)->where('price_monthly', '>', 0)->get();
        $this->assertGreaterThan(40, $paid->count());
        // Trois paliers : Essentiel 5 000 F par défaut, Standard 10 000 F (envois, stockage, API payantes), Premium 25 000 F (IA, visio)
        $this->assertEqualsCanonicalizing([5000.0, 10000.0, 25000.0], $paid->pluck('price_monthly')->map(fn ($v) => (float) $v)->unique()->values()->all());
        $this->assertEqualsCanonicalizing(\App\Support\PricingGrid::TIER_PREMIUM, $paid->where('price_monthly', 25000)->pluck('key')->all());
        $this->assertSame(['cinetpay', 'wave', 'orange_money', 'mobile_money', 'bank_transfer', 'cash'], PaymentGateway::whereNull('company_id')->orderBy('sort')->pluck('driver')->all());
        $this->assertSame(['mobile_money', 'bank_transfer'], PaymentGateway::whereNull('company_id')->where('enabled', true)->orderBy('sort')->pluck('driver')->all());
        $store = $this->api($token)->getJson('/api/store')->assertOk()->json();
        $this->assertSame(5000, (int) collect($store['modules'] ?? $store['data'] ?? [])->firstWhere('key', 'crm')['offers']['monthly']);

        // Module acheté qui expire dans 6 jours → rappel à J-7, puis J-3, J-1, le jour même ; une fois par palier
        Carbon::setTestNow('2026-11-10 08:40:00');
        CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => 'crm'], ['enabled' => true, 'source' => 'purchase', 'billing' => 'monthly', 'expires_at' => now()->addDays(6)]);
        $boot = $this->api($token)->getJson('/api/bootstrap')->assertOk()->json('expiring_modules');
        $this->assertEquals([['crm', 6, 'purchase']], collect($boot)->map(fn ($m) => [$m['key'], $m['days_left'], $m['source']])->all());
        $count = fn () => AppNotification::where('user_id', $admin->id)->where('title', 'like', 'Abonnement « CRM%')->count();
        $this->artisan('erp:store-expiry')->assertSuccessful();
        $this->artisan('erp:store-expiry')->assertSuccessful();
        $this->assertSame(1, $count());
        $this->assertStringContainsString('fin dans 6 jours', AppNotification::where('user_id', $admin->id)->latest('id')->value('title'));
        Carbon::setTestNow('2026-11-14 09:00:00'); // J-2 → palier 3
        $this->artisan('erp:store-expiry')->assertSuccessful();
        Carbon::setTestNow('2026-11-16 07:00:00'); // le jour même
        $this->artisan('erp:store-expiry')->assertSuccessful();
        $this->assertSame(3, $count());
        $this->assertStringContainsString('aujourd\'hui', AppNotification::where('user_id', $admin->id)->latest('id')->value('title'));

        // Renouvellement payé : nouvelle échéance, rappels remis à zéro
        $order = \App\Models\ModuleOrder::create(['company_id' => $company->id, 'user_id' => $admin->id, 'module_key' => 'crm', 'billing' => 'monthly', 'amount' => 10000, 'currency' => 'XOF', 'status' => 'pending', 'number' => 'MOD-T-1']);
        app(\App\Store\ModuleStore::class)->activate($order);
        $cm = CompanyModule::where('company_id', $company->id)->where('module_key', 'crm')->first();
        $this->assertEquals(['2026-12-16', null], [$cm->expires_at->toDateString(), $cm->expiry_notice]);
        $this->assertSame([], $this->api($token)->getJson('/api/bootstrap')->json('expiring_modules'));
    }

    public function test_super_admin_bulk_price(): void
    {
        [, , $admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        [, , $client] = $this->makeCompany('Client');
        $this->api($client)->postJson('/api/admin/modules/bulk-price', ['price_monthly' => 12000])->assertForbidden();
        $r = $this->api($admin)->postJson('/api/admin/modules/bulk-price', ['price_monthly' => 12000])->assertOk()->json();
        $this->assertGreaterThan(40, $r['updated']);
        $crm = Module::where('key', 'crm')->first();
        $this->assertEquals([12000, 120000, 288000], [(float) $crm->price_monthly, (float) $crm->price_yearly, (float) $crm->price_one_time]);
        $this->assertEquals(0, (float) Module::where('key', 'core')->value('price_monthly'));
    }

    public function test_super_admin_aligns_prices_on_the_free_plan(): void
    {
        [, , $admin] = $this->makeCompany('Yomara', 'entreprise', superAdmin: true);
        \App\Models\Plan::updateOrCreate(['code' => 'gratuit'], ['name' => 'Gratuit', 'price_monthly' => 0, 'is_free' => true, 'is_active' => true, 'modules' => ['ventes', 'crm']]);
        \App\Models\Module::where('key', 'crm')->update(['billing_mode' => 'both', 'price_monthly' => 5000, 'price_one_time' => 120000]);
        \App\Models\Module::where('key', 'visio')->update(['billing_mode' => 'free', 'price_monthly' => 0]);
        \App\Models\Module::where('key', 'paie')->update(['billing_mode' => 'both', 'price_monthly' => 7000]);

        $preview = $this->api($admin)->postJson('/api/admin/modules/align-prices')->assertOk()->json();
        $this->assertContains('crm', array_column($preview['to_free'], 'key'));
        $this->assertContains('visio', array_column($preview['to_price'], 'key'));
        $this->assertSame(5000, (int) \App\Models\Module::where('key', 'crm')->value('price_monthly')); // aperçu : rien ne change

        $this->api($admin)->postJson('/api/admin/modules/align-prices', ['apply' => true])->assertOk();
        $crm = \App\Models\Module::where('key', 'crm')->first();
        $visio = \App\Models\Module::where('key', 'visio')->first();
        $this->assertSame(['free', 0.0], [$crm->billing_mode, (float) $crm->price_monthly]);
        $this->assertSame(['both', 25000.0, 250000.0], [$visio->billing_mode, (float) $visio->price_monthly, (float) $visio->price_yearly]);
        $this->assertSame(7000.0, (float) \App\Models\Module::where('key', 'paie')->value('price_monthly')); // déjà tarifé : inchangé
    }
}
