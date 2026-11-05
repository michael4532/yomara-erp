<?php

use App\Support\PricingGrid;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nouvelle grille des modules à l'unité : Essentiel 5 000 F, Standard 10 000 F, Premium 25 000 F par mois
 * (annuel = 10 mois). Seuls les prix encore à leur valeur d'origine sont modifiés : un prix fixé par le
 * super admin est conservé.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('modules')) {
            return;
        }
        foreach (DB::table('modules')->whereIn('price_monthly', [10000, 25000])->get() as $m) {
            $price = PricingGrid::TIERS[PricingGrid::tierOf($m->key)]['price'];
            if ((float) $m->price_monthly === (float) $price) {
                continue;
            }
            $old = (float) $m->price_monthly;
            $update = ['price_monthly' => $price];
            if ((float) $m->price_yearly === $old * 10) {
                $update['price_yearly'] = $price * 10;
            }
            if ((float) $m->price_one_time === $old * 24) {
                $update['price_one_time'] = $price * 24;
            }
            DB::table('modules')->where('id', $m->id)->update($update);
        }
        DB::table('modules')->where('key', 'paie')->where('name', 'Paie Côte d\'Ivoire')->update(['name' => 'Paie multi-pays']);
    }

    public function down(): void {}
};
