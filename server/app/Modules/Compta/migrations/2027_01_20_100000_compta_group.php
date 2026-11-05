<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Codes de rattachement : une filiale autorise une société mère à consolider ses comptes
        Schema::create('compta_group_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('code', 16)->unique();
            $t->timestamp('expires_at');
            $t->timestamps();
        });
        // Périmètre de consolidation de la société mère
        Schema::create('compta_group_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('parent_id')->index();
            $t->foreignId('member_id')->index();
            $t->decimal('interest_pct', 7, 3)->default(100);  // pourcentage d'intérêts
            $t->decimal('control_pct', 7, 3)->default(100);   // pourcentage de contrôle
            $t->string('method', 4)->default('ig');            // ig : intégration globale, ip : proportionnelle, mee : mise en équivalence
            $t->decimal('investment_cost', 18, 2)->default(0); // coût des titres chez la mère
            $t->decimal('equity_at_acquisition', 18, 2)->default(0);
            $t->date('acquisition_date')->nullable();
            $t->decimal('rate', 18, 6)->nullable();           // cours de conversion si autre monnaie
            $t->timestamps();
            $t->unique(['parent_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compta_group_members');
        Schema::dropIfExists('compta_group_links');
    }
};
