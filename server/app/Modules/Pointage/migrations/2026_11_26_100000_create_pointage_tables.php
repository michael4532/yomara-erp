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

        // Horaires de travail : par jour ISO (1 = lundi … 7 = dimanche) { start, end, break } ou null (repos)
        Schema::create('pt_schedules', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->json('days');
            $t->unsignedSmallInteger('tolerance')->default(10); // minutes de retard tolérées
            $t->boolean('is_default')->default(false);
            $sync($t);
        });

        // Badge de pointage d'un salarié (même identifiant que sa fiche) : ce que la borne a besoin de connaître
        Schema::create('pt_badges', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->string('matricule', 30)->nullable();
            $t->string('department')->nullable();
            $t->foreignId('user_id')->nullable()->index();
            $t->uuid('schedule_id')->nullable();
            $t->string('pin_hash', 64)->nullable();
            $t->boolean('self_allowed')->default(true); // peut pointer depuis son téléphone
            $t->boolean('active')->default(true);
            $sync($t);
        });

        Schema::create('pt_punches', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('employee_id')->index();
            $t->dateTime('at');
            $t->date('date')->index();
            $t->string('kind', 3); // in | out
            $t->string('source', 10); // kiosk | self | manual
            $t->string('device', 80)->nullable();
            $t->string('note')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->boolean('late_sync')->default(false); // arrivé au serveur longtemps après l'heure indiquée (appareil hors ligne)
            $sync($t);
            $t->index(['company_id', 'employee_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pt_punches');
        Schema::dropIfExists('pt_badges');
        Schema::dropIfExists('pt_schedules');
    }
};
