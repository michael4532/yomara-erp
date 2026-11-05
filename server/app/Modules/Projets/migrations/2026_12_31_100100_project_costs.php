<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rattachement d'un document (vente ou achat) à un projet : rentabilité du projet
        Schema::table('trade_documents', fn (Blueprint $t) => $t->uuid('project_id')->nullable()->index());
        // Dernier seuil d'alerte budget envoyé (80 / 100)
        Schema::table('prj_projects', fn (Blueprint $t) => $t->unsignedSmallInteger('cost_alerted')->nullable());
    }

    public function down(): void
    {
        Schema::table('prj_projects', fn (Blueprint $t) => $t->dropColumn('cost_alerted'));
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn('project_id'));
    }
};
