<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Écart d'acquisition : amortissement sur une durée (années) et/ou dépréciation constatée à une date
        Schema::table('compta_group_members', function (Blueprint $t) {
            $t->unsignedSmallInteger('goodwill_years')->nullable()->after('historical_rate');
            $t->decimal('goodwill_impairment', 18, 2)->default(0)->after('goodwill_years');
            $t->date('goodwill_impairment_date')->nullable()->after('goodwill_impairment');
        });
    }

    public function down(): void
    {
        Schema::table('compta_group_members', fn (Blueprint $t) => $t->dropColumn(['goodwill_years', 'goodwill_impairment', 'goodwill_impairment_date']));
    }
};
