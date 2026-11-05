<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Versions diffusées (plateforme)
        Schema::create('upd_releases', function (Blueprint $t) {
            $t->id();
            $t->string('version', 20);
            $t->string('channel', 10)->default('stable');
            $t->text('notes')->nullable();
            $t->string('path')->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('sha256', 64)->nullable();
            $t->string('php_min', 10)->default('8.2');
            $t->boolean('mandatory')->default(false);
            $t->boolean('published')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('downloads')->default(0);
            $t->json('apps')->nullable(); // liens des applications (windows, macos, android…)
            $t->timestamps();
            $t->unique(['version', 'channel']);
        });
        // Installations qui interrogent le serveur de mises à jour (plateforme)
        Schema::create('upd_installations', function (Blueprint $t) {
            $t->id();
            $t->string('install_id', 64)->unique();
            $t->string('name', 190)->nullable();
            $t->string('url', 255)->nullable();
            $t->string('version', 20)->nullable();
            $t->string('php', 20)->nullable();
            $t->string('os', 40)->nullable();
            $t->string('database', 20)->nullable();
            $t->string('ip', 64)->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamp('updated_at_version')->nullable();
            $t->timestamps();
        });
        // Historique des mises à jour appliquées (chaque installation)
        Schema::create('upd_history', function (Blueprint $t) {
            $t->id();
            $t->string('from_version', 20)->nullable();
            $t->string('to_version', 20);
            $t->string('status', 12); // installing, done, failed, rolled_back
            $t->text('log')->nullable();
            $t->string('backup')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upd_history');
        Schema::dropIfExists('upd_installations');
        Schema::dropIfExists('upd_releases');
    }
};
