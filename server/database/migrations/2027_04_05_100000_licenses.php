<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Licences d'installation (plateforme SaaS) : une licence = une entreprise installée sur le serveur
 * ou l'ordinateur du client, liée à un appareil. Ses modules sont portés par une société « licence »
 * (companies.status = 'license') pour réutiliser la boutique, les abonnements et les paiements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('owner_company_id')->index();
            $t->unsignedBigInteger('company_id')->index(); // société « licence » qui porte les modules
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('key', 40)->unique();
            $t->string('company_name');
            $t->string('target', 20)->default('server'); // server, windows, mac, linux
            $t->string('status', 20)->default('unused'); // unused, active, suspended, revoked
            $t->string('fingerprint', 128)->nullable();
            $t->string('device_name')->nullable();
            $t->json('device')->nullable();
            $t->string('install_url')->nullable();
            $t->string('installed_company')->nullable();
            $t->string('app_version', 30)->nullable();
            $t->unsignedInteger('users_count')->nullable();
            $t->unsignedInteger('resets')->default(0);
            $t->timestamp('activated_at')->nullable();
            $t->timestamp('last_seen_at')->nullable()->index();
            $t->string('last_ip', 64)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('license_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('license_id')->index();
            $t->string('type', 30); // created, activated, refused, reset, suspended, reactivated, revoked, renamed
            $t->string('ip', 64)->nullable();
            $t->json('data')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_events');
        Schema::dropIfExists('licenses');
    }
};
