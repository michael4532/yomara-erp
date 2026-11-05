<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Support\PricingGrid;
use Tests\TestCase;

class PricingTiersTest extends TestCase
{
    public function test_modules_follow_three_price_tiers(): void
    {
        [, , $token] = $this->makeCompany('Grille');
        $prices = Module::where('is_core', false)->where('price_monthly', '>', 0)->pluck('price_monthly', 'key')->map(fn ($p) => (float) $p);
        $this->assertEqualsCanonicalizing([5000.0, 10000.0, 25000.0], $prices->unique()->values()->all());
        $this->assertSame(10000.0, $prices['sms']); // envois payés par la plateforme : Standard
        $this->assertSame(5000.0, $prices['crm']); // par défaut : Essentiel
        $this->assertSame(5000.0, $prices['paie']);
        $this->assertSame(25000.0, $prices['ia']); // IA : Premium
        $this->assertSame(100000.0, (float) Module::where('key', 'sms')->value('price_yearly')); // annuel = 10 mois
        $this->assertSame('Paie multi-pays', Module::where('key', 'paie')->value('name'));

        config(['erp.mode' => 'saas']);
        $store = collect($this->api($token)->getJson('/api/store')->assertOk()->json('modules'))->keyBy('key');
        $this->assertSame(['standard', 'Standard'], [$store['sms']['tier'], $store['sms']['tier_label']]);
        $this->assertSame('essentiel', $store['compta']['tier']);
        $this->assertSame('premium', $store['visio']['tier']);
        // Prix fixé par le super admin : hors palier
        $this->assertNull(PricingGrid::tierOf('crm', 7500.0));
    }
}
