<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiche contact inspirée de Dolibarr et Odoo : statut de prospection et potentiel (prospects), origine,
 * commercial attribué, site web et interlocuteurs (personnes à contacter dans l'entreprise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $t) {
            if (! Schema::hasColumn('partners', 'prospect_status')) {
                $t->string('prospect_status', 20)->nullable();
                $t->string('prospect_level', 10)->nullable();
                $t->string('source', 30)->nullable();
                $t->unsignedBigInteger('salesperson_id')->nullable();
                $t->string('website', 255)->nullable();
                $t->json('contacts')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn(['prospect_status', 'prospect_level', 'source', 'salesperson_id', 'website', 'contacts']));
    }
};
