<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Support\ModuleRegistry;
use Illuminate\Database\Seeder;

/** Catalogue de modules + formules de départ (prix modifiables par le super admin). */
class PlatformSeeder extends Seeder
{
    public function run(): void
    {
        app(ModuleRegistry::class)->syncToDatabase();

        $plans = \App\Support\PricingGrid::plans();

        foreach ($plans as $p) {
            Plan::firstOrCreate(['code' => $p['code']], $p + ['currency' => config('erp.defaults.currency')]);
        }
    }
}
