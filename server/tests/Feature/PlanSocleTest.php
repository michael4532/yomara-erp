<?php

namespace Tests\Feature;

use App\Models\Module;
use App\Models\Plan;
use App\Support\ModuleRegistry;
use Tests\TestCase;

/** Socle : les modules de la formule gratuite sont compris d'office dans toutes les formules. */
class PlanSocleTest extends TestCase
{
    public function test_paid_plans_inherit_free_plan_modules(): void
    {
        config(['erp.mode' => 'saas']);
        [$company] = $this->makeCompany('Socle SARL', null); // synchronise aussi le catalogue des modules
        [$a, $b, $c] = Module::where('is_core', false)->where('is_active', true)->where('is_available', true)->where('billing_mode', '!=', 'free')
            ->orderBy('sort')->get()->filter(fn ($m) => array_diff((array) $m->depends, ['core']) === [])->take(3)->pluck('key')->values()->all() + [null, null, null];
        $this->assertNotNull($c);
        Plan::query()->update(['is_active' => false]);
        Plan::create(['code' => 'gratuit-t', 'name' => 'Gratuit', 'price_monthly' => 0, 'is_free' => true, 'is_active' => true, 'sort' => 1, 'modules' => [$a, $b], 'currency' => 'XOF']);
        $pro = Plan::create(['code' => 'pro-t', 'name' => 'Pro', 'price_monthly' => 25000, 'is_active' => true, 'sort' => 2, 'modules' => [$c], 'currency' => 'XOF']);

        $this->assertEqualsCanonicalizing([$a, $b, $c], $pro->effectiveModules());
        $this->assertEqualsCanonicalizing([$a, $b, $c], $pro->offer(0)['modules']);

        $company->forceFill(['plan_id' => $pro->id])->save();
        app(ModuleRegistry::class)->flush();
        $on = app(ModuleRegistry::class)->enabledFor($company->fresh('plan'));
        foreach ([$a, $b, $c] as $k) {
            $this->assertContains($k, $on, "$k compris dans la formule Pro (socle + formule)");
        }

        // Page Tarifs : « Tout Gratuit, plus : » puis seulement les modules ajoutés
        $names = Module::whereIn('key', [$a, $b, $c])->pluck('name', 'key');
        $html = $this->get('/tarifs')->assertOk()->getContent();
        $this->assertStringContainsString('Tout Gratuit, plus :', $html);
        $pro_block = substr($html, strpos($html, 'Tout Gratuit, plus :'), 600);
        $this->assertStringContainsString(e($names[$c]), $pro_block);
        $this->assertStringNotContainsString('<li>'.e($names[$a]).'</li>', $pro_block);

        // Le socle suit la formule gratuite : un module retiré du gratuit n'est plus compris dans Pro
        Plan::where('code', 'gratuit-t')->first()->update(['modules' => [$a]]);
        $this->assertEqualsCanonicalizing([$a, $c], $pro->fresh()->effectiveModules());
    }
}
