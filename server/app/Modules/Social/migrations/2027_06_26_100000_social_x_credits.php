<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Crédits X consommés par chaque publication sur X (1, ou davantage avec un lien)
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('so_targets', 'credits')) {
            Schema::table('so_targets', fn (Blueprint $t) => $t->unsignedSmallInteger('credits')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('so_targets', 'credits')) {
            Schema::table('so_targets', fn (Blueprint $t) => $t->dropColumn('credits'));
        }
    }
};
