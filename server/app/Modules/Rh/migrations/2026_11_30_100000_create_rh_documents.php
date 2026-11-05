<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sync = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };

        // Modèles (texte avec champs {nom}, {fonction}…), modifiables par la RH
        Schema::create('rh_doc_templates', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('type', 20); // contract, attestation, certificate, warning, stc, other
            $t->string('name');
            $t->text('body');
            $t->boolean('self_service')->default(false); // le salarié peut l'obtenir seul depuis son espace
            $t->boolean('active')->default(true);
            $sync($t);
        });

        // Documents établis : texte figé au moment de l'émission
        Schema::create('rh_documents', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('employee_id')->index();
            $t->uuid('template_id')->nullable();
            $t->string('type', 20);
            $t->string('title');
            $t->string('number', 40)->nullable();
            $t->date('date');
            $t->longText('body')->nullable();
            $t->json('data')->nullable(); // solde de tout compte : lignes, motif de sortie…
            $t->string('status', 12)->default('issued'); // requested, draft, issued, cancelled
            $t->string('note')->nullable(); // demande du salarié, motif d'annulation
            $t->foreignId('requested_by')->nullable();
            $t->foreignId('issued_by')->nullable();
            $t->timestamp('issued_at')->nullable();
            $sync($t);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rh_documents');
        Schema::dropIfExists('rh_doc_templates');
    }
};
