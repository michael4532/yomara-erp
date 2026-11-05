<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grille tarifaire : Starter 15 000, Business 35 000, Professional 75 000, Enterprise 150 000 F / mois, Sur mesure sur devis.
 * Les modules premium (multi-agences, consolidation, fabrication, événementiel…) sont réservés à Enterprise ou vendus à part.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('plans')) {
            return;
        }
        foreach (\App\Support\PricingGrid::plans() as $p) {
            $row = ['name' => $p['name'], 'description' => $p['description'], 'price_monthly' => $p['price_monthly'], 'price_yearly' => $p['price_yearly'],
                'max_users' => $p['max_users'], 'modules' => json_encode($p['modules']), 'sort' => $p['sort'], 'is_active' => $p['is_active'], 'updated_at' => now()];
            if (DB::table('plans')->where('code', $p['code'])->exists()) {
                DB::table('plans')->where('code', $p['code'])->update($row);
            } else {
                DB::table('plans')->insert($row + ['code' => $p['code'], 'currency' => config('erp.defaults.currency', 'XOF'), 'created_at' => now()]);
            }
        }
    }

    public function down(): void {}
};
