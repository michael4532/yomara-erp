<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Connexions bancaires : relevés récupérés automatiquement (Wave Business, banques européennes, e-mail de relevés)
        Schema::create('tr_bank_connections', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('account_id')->index();
            $t->string('provider', 20); // wave, gocardless, email
            $t->string('label', 120)->nullable();
            $t->string('status', 12)->default('pending'); // pending, active, error, expired, paused
            $t->text('credentials')->nullable(); // chiffré
            $t->json('meta')->nullable();
            $t->string('inbound_token', 48)->nullable()->unique();
            $t->date('sync_from')->nullable();
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamp('next_sync_at')->nullable();
            $t->string('last_error', 255)->nullable();
            $t->unsignedInteger('imported')->default(0);
            $t->decimal('last_balance', 15, 2)->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
        });

        // Règles de rapprochement : « libellé contient CANAL+ → charge Abonnements », « FRAIS → frais bancaires »…
        Schema::create('tr_bank_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('account_id')->nullable(); // null = tous les comptes
            $t->string('name', 120);
            $t->string('contains', 255); // mots-clés séparés par des virgules (un seul suffit)
            $t->string('direction', 4)->default('any'); // in, out, any
            $t->decimal('amount_min', 15, 2)->nullable();
            $t->decimal('amount_max', 15, 2)->nullable();
            $t->string('action', 10)->default('operation'); // operation, ignore
            $t->string('category', 40)->nullable();
            $t->string('label', 190)->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('hits')->default(0);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });

        Schema::table('tr_statement_lines', function (Blueprint $t) {
            $t->string('external_id', 120)->nullable();
            $t->string('counterparty', 190)->nullable();
            $t->unsignedBigInteger('rule_id')->nullable();
        });
        Schema::table('tr_statements', function (Blueprint $t) {
            $t->string('source', 20)->nullable(); // file, ofx, mt940, camt, cfonb, wave, gocardless, email
            $t->decimal('closing_balance', 15, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tr_bank_rules');
        Schema::dropIfExists('tr_bank_connections');
        Schema::table('tr_statement_lines', fn (Blueprint $t) => $t->dropColumn(['external_id', 'counterparty', 'rule_id']));
        Schema::table('tr_statements', fn (Blueprint $t) => $t->dropColumn(['source', 'closing_balance']));
    }
};
