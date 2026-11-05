<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables du socle : plateforme (plans, modules), sociétés, utilisateurs,
 * rôles, présence, synchronisation hors ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---------- Plateforme ----------
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price_monthly', 15, 2)->default(0);
            $table->decimal('price_yearly', 15, 2)->default(0);
            $table->string('currency', 3)->default('XOF');
            $table->unsignedInteger('max_users')->nullable(); // null = illimité
            $table->json('modules')->nullable();              // modules inclus
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('modules', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category', 50)->nullable();
            $table->string('icon', 50)->nullable();
            $table->json('depends')->nullable();
            $table->boolean('is_core')->default(false);       // toujours actif
            $table->boolean('is_available')->default(false);  // code présent
            $table->boolean('is_active')->default(true);      // activé globalement par le super admin
            $table->decimal('price_monthly', 15, 2)->default(0);
            $table->decimal('price_yearly', 15, 2)->default(0);
            $table->string('currency', 3)->default('XOF');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // ---------- Sociétés ----------
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 2)->default('CI');
            $table->string('currency', 3)->default('XOF');
            $table->string('tax_id', 100)->nullable();   // NCC
            $table->string('trade_register', 100)->nullable(); // RCCM
            $table->string('logo')->nullable();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->string('status', 20)->default('active'); // active, trial, suspended
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('subscription_ends_at')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();
        });

        Schema::create('company_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('module_key', 50);
            $table->boolean('enabled')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->decimal('price_override', 15, 2)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'module_key']);
        });

        // ---------- Utilisateurs & rôles ----------
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('permissions')->nullable(); // ["*"] ou ["ventes.*", "stock.view", ...]
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone', 50)->nullable();
            $table->string('password');
            $table->boolean('is_super_admin')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->string('last_platform', 30)->nullable(); // windows, macos, linux, web, android, ios
            $table->string('last_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // ---------- Synchronisation hors ligne ----------
        // Journal monotone des changements : le curseur de pull = id.
        Schema::create('sync_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('entity', 50);
            $table->uuid('record_id');
            $table->string('op', 10); // upsert | delete
            $table->timestamp('created_at')->nullable();
            $table->index(['company_id', 'id']);
        });

        // Mutations déjà appliquées (idempotence des envois répétés).
        Schema::create('sync_mutations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->foreignId('user_id')->nullable();
            $table->string('entity', 50);
            $table->uuid('record_id');
            $table->string('status', 20); // applied | conflict | rejected
            $table->text('message')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Numérotation des documents (factures, devis…), par société et par année.
        Schema::create('sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 50);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'key', 'year']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->index();
            $table->foreignId('user_id')->nullable();
            $table->string('action', 100);
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('properties')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['activity_logs', 'sequences', 'sync_mutations', 'sync_changes', 'sessions',
            'password_reset_tokens', 'users', 'roles', 'company_modules', 'companies', 'modules', 'plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
