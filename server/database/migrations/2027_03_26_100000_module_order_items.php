<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Commandes de packs : liste figée des modules achetés ensemble. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('module_orders') && ! Schema::hasColumn('module_orders', 'items')) {
            Schema::table('module_orders', fn (Blueprint $t) => $t->json('items')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('module_orders', 'items')) {
            Schema::table('module_orders', fn (Blueprint $t) => $t->dropColumn('items'));
        }
    }
};
