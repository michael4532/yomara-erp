<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Palier Standard (modules qui coûtent à la plateforme : SMS, WhatsApp, e-mailing, sites et boutique, boîtes mail,
 * stockage, signature, avis Google, réseaux, veille, acquisition) : 30 000 F CFA / mois.
 * Seuls les modules encore au prix précédent du palier (10 000) ou sans prix sont changés : un prix choisi par le super admin est conservé.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('modules')) {
            return;
        }
        DB::table('modules')->whereIn('key', \App\Support\PricingGrid::TIER_STANDARD)
            ->where(fn ($q) => $q->where('price_monthly', 10000)->orWhereNull('price_monthly')->orWhere('price_monthly', 0))
            ->where('billing_mode', '!=', 'free')
            ->update(['price_monthly' => 30000, 'price_yearly' => 300000, 'price_one_time' => 720000]);
    }

    public function down(): void {}
};
