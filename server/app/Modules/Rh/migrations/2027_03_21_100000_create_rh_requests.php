<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demandes des salariés (congé, augmentation, avance, démission, formation…) traitées comme des tickets :
 * numéro, statut, fil d'échanges, décision. Chaque salarié peut avoir un responsable direct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rh_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 30)->nullable();
            $t->uuid('employee_id')->index();
            $t->uuid('manager_id')->nullable()->index(); // responsable au moment de la demande (fiche salarié)
            $t->string('type', 20);
            $t->string('subject', 200);
            $t->text('body')->nullable();
            $t->decimal('amount', 15, 2)->nullable(); // augmentation, avance
            $t->date('start_date')->nullable(); // congé
            $t->date('end_date')->nullable();
            $t->string('leave_type', 20)->nullable();
            $t->date('effective_date')->nullable(); // démission (dernier jour), mutation
            $t->uuid('leave_id')->nullable(); // congé créé à partir de la demande
            $t->string('status', 15)->default('submitted')->index(); // submitted, in_review, approved, rejected, cancelled
            $t->string('decision_note', 1000)->nullable();
            $t->json('messages')->nullable(); // fil d'échanges : [{at, user_id, name, role, text}]
            $t->foreignId('requested_by')->nullable();
            $t->foreignId('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::table('rh_employees', function (Blueprint $t) {
            $t->uuid('manager_id')->nullable()->index(); // responsable direct (sinon : titulaire du poste supérieur)
        });
    }

    public function down(): void
    {
        Schema::table('rh_employees', fn (Blueprint $t) => $t->dropColumn('manager_id'));
        Schema::dropIfExists('rh_requests');
    }
};
