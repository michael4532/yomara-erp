<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Exercices comptables : clôture, impôt sur le résultat, affectation du résultat
        Schema::create('compta_years', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedSmallInteger('year');
            $t->string('status', 10)->default('open'); // open, closed
            $t->decimal('result', 16, 2)->nullable();
            $t->json('fiscal')->nullable();            // réintégrations, déductions, base, impôt
            $t->json('allocation')->nullable();        // affectation décidée par l'assemblée
            $t->date('allocation_date')->nullable();
            $t->string('previous_lock', 10)->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->unsignedBigInteger('closed_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compta_years');
    }
};
