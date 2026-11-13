<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// « very_urgent » (11 caractères) ne tenait pas dans la colonne de 10 : erreur 500 sous MySQL/MariaDB.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['af_requests'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'priority')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('priority', 20)->default('normal')->change());
            }
        }
    }

    public function down(): void {}
};
