<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('woo_stores', 'platform')) {
            // Boutiques connectées : WooCommerce ou Shopify (mêmes tables, même logique de commandes)
            Schema::table('woo_stores', fn (Blueprint $t) => $t->string('platform', 12)->default('woocommerce')->index());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('woo_stores', 'platform')) {
            Schema::table('woo_stores', fn (Blueprint $t) => $t->dropColumn('platform'));
        }
    }
};
