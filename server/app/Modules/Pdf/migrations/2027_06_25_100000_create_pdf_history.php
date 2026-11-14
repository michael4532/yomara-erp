<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Historique personnel des fichiers produits par les outils PDF (30 derniers, 30 jours). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pdf_history')) {
            return;
        }
        Schema::create('pdf_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('op', 20);
            $t->string('name', 190);
            $t->string('source', 190)->nullable();
            $t->string('mime', 60);
            $t->unsignedInteger('size');
            $t->unsignedSmallInteger('files')->default(1);
            $t->string('path', 255);
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pdf_history');
    }
};
