<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Solde de tout compte calculé à la sortie du salarié (repris dans la paie du mois de sortie). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rh_employees', function (Blueprint $t) {
            if (! Schema::hasColumn('rh_employees', 'settlement')) {
                $t->json('settlement')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('rh_employees', fn (Blueprint $t) => $t->dropColumn('settlement'));
    }
};
