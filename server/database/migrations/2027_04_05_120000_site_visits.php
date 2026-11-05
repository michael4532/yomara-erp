<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fréquentation du site de présentation (anonymisée : empreinte du visiteur renouvelée chaque jour). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $t) {
            $t->id();
            $t->date('day')->index();
            $t->string('visitor', 64)->index();
            $t->string('event', 30)->default('view'); // view, signup_form, signup, download, contact, app_open
            $t->string('path', 190)->nullable();
            $t->string('referrer', 190)->nullable();
            $t->string('source', 60)->nullable();
            $t->string('campaign', 120)->nullable();
            $t->string('device', 20)->nullable();
            $t->string('os', 20)->nullable();
            $t->string('detail', 120)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visits');
    }
};
