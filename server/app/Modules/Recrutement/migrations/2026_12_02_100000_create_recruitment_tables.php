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

        Schema::create('rc_jobs', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('title');
            $t->string('department')->nullable();
            $t->string('location')->nullable();
            $t->string('contract_type', 20)->default('CDI');
            $t->string('salary')->nullable(); // fourchette affichée (facultative)
            $t->text('description')->nullable();
            $t->text('requirements')->nullable();
            $t->unsignedSmallInteger('positions')->default(1);
            $t->string('status', 10)->default('draft'); // draft, open, closed
            $t->boolean('published')->default(true); // visible sur la page Emplois quand ouverte
            $t->date('closes_at')->nullable();
            $t->foreignId('owner_id')->nullable(); // responsable du recrutement
            $sync($t);
        });

        Schema::create('rc_applications', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('job_id')->nullable()->index(); // null : candidature spontanée
            $t->string('first_name');
            $t->string('last_name');
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('city')->nullable();
            $t->string('source', 20)->default('manual'); // website, manual, referral, whatsapp, facebook, linkedin, other
            $t->text('cover_letter')->nullable();
            $t->string('cv_path')->nullable();
            $t->string('cv_name')->nullable();
            $t->string('stage', 12)->default('new'); // new, screening, interview, offer, hired, rejected
            $t->unsignedTinyInteger('rating')->nullable(); // 1 à 5
            $t->text('notes')->nullable();
            $t->string('reject_reason')->nullable();
            $t->string('expected_salary')->nullable();
            $t->date('available_from')->nullable();
            $t->timestamp('consent_at')->nullable();
            $t->timestamp('stage_at')->nullable();
            $t->uuid('employee_id')->nullable(); // fiche créée à l'embauche
            $sync($t);
            $t->index(['company_id', 'stage']);
        });

        Schema::create('rc_interviews', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('application_id')->index();
            $t->dateTime('starts_at');
            $t->unsignedSmallInteger('duration')->default(45);
            $t->string('location')->nullable(); // bureau, lien de visio, téléphone
            $t->json('interviewer_ids')->nullable();
            $t->string('status', 10)->default('planned'); // planned, done, cancelled
            $t->unsignedTinyInteger('score')->nullable();
            $t->string('recommendation', 6)->nullable(); // yes, maybe, no
            $t->text('feedback')->nullable();
            $sync($t);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rc_interviews');
        Schema::dropIfExists('rc_applications');
        Schema::dropIfExists('rc_jobs');
    }
};
