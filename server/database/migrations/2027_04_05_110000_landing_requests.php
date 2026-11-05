<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Demandes reçues depuis le site de présentation : module sur mesure, démonstration, devis, contact. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_requests', function (Blueprint $t) {
            $t->id();
            $t->string('type', 30)->default('contact');
            $t->string('name');
            $t->string('company')->nullable();
            $t->string('email');
            $t->string('phone', 60)->nullable();
            $t->string('budget', 60)->nullable();
            $t->text('message');
            $t->string('status', 20)->default('new'); // new, handled
            $t->string('ip', 64)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_requests');
    }
};
