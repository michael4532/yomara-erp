<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Prévision manuelle limitée à certains scénarios (null = tous)
        Schema::table('tr_forecasts', fn (Blueprint $t) => $t->json('scenarios')->nullable());
    }

    public function down(): void
    {
        Schema::table('tr_forecasts', fn (Blueprint $t) => $t->dropColumn('scenarios'));
    }
};
