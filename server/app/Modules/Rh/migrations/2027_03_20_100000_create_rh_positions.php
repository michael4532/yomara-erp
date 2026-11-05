<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Référentiel RH : catégories professionnelles (grille de la convention collective, salaire minimum)
 * et fiches de poste (missions, compétences, rattachement, effectif prévu). Le salarié est rattaché à un poste.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sync = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };

        Schema::create('rh_categories', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('code', 30);
            $t->string('label', 120)->nullable();
            $t->string('group', 40)->nullable(); // ouvriers & employés, agents de maîtrise, cadres
            $t->decimal('min_salary', 15, 2)->default(0); // salaire catégoriel minimum
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $sync($t);
        });

        Schema::create('rh_positions', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('title', 160);
            $t->string('code', 30)->nullable();
            $t->string('department', 120)->nullable();
            $t->uuid('category_id')->nullable()->index();
            $t->uuid('parent_id')->nullable()->index(); // poste du supérieur hiérarchique
            $t->string('contract_type', 20)->nullable();
            $t->decimal('salary_min', 15, 2)->nullable();
            $t->decimal('salary_max', 15, 2)->nullable();
            $t->unsignedSmallInteger('headcount')->nullable(); // effectif prévu
            $t->text('purpose')->nullable(); // raison d'être du poste
            $t->text('missions')->nullable();
            $t->text('skills')->nullable();
            $t->text('requirements')->nullable(); // formation, expérience
            $t->text('conditions')->nullable(); // lieu, horaires, déplacements, équipements
            $t->boolean('active')->default(true);
            $sync($t);
        });

        Schema::table('rh_employees', function (Blueprint $t) {
            $t->uuid('position_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('rh_employees', fn (Blueprint $t) => $t->dropColumn('position_id'));
        Schema::dropIfExists('rh_positions');
        Schema::dropIfExists('rh_categories');
    }
};
