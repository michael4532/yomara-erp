<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Note de frais rattachée à un projet (rentabilité du projet)
        Schema::table('nf_expenses', fn (Blueprint $t) => $t->uuid('project_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('nf_expenses', fn (Blueprint $t) => $t->dropColumn('project_id'));
    }
};
