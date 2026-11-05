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

        Schema::create('tr_accounts', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->string('kind', 15)->default('bank'); // bank | cash | mobile_money
            $t->string('bank_name')->nullable();
            $t->string('number', 60)->nullable(); // RIB, n° de téléphone marchand…
            $t->decimal('opening_balance', 15, 2)->default(0);
            $t->date('opening_date')->nullable();
            $t->string('gl_code', 12)->nullable(); // compte comptable (521, 571, 5215…)
            $t->json('methods')->nullable(); // moyens de paiement encaissés ici par défaut
            $t->decimal('low_balance', 15, 2)->nullable(); // seuil d'alerte
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $sync($t);
        });

        Schema::create('tr_operations', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('account_id')->index();
            $t->date('date');
            $t->decimal('amount', 15, 2); // + entrée, − sortie
            $t->string('label');
            $t->string('category', 30);
            $t->uuid('partner_id')->nullable();
            $t->string('reference', 100)->nullable();
            $t->uuid('transfer_id')->nullable()->index(); // virement interne : les deux opérations partagent cet id
            $t->text('notes')->nullable();
            $t->foreignId('user_id')->nullable();
            $sync($t);
        });

        Schema::create('tr_forecasts', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('label');
            $t->decimal('amount', 15, 2); // + entrée, − sortie
            $t->date('date');
            $t->string('repeat', 10)->default('none'); // none | weekly | monthly
            $t->date('until')->nullable();
            $t->string('category', 30)->nullable();
            $sync($t);
        });

        Schema::create('tr_statements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('account_id')->index();
            $t->string('filename');
            $t->date('date_from')->nullable();
            $t->date('date_to')->nullable();
            $t->unsignedInteger('lines')->default(0);
            $t->unsignedInteger('duplicates')->default(0);
            $t->string('user_name')->nullable();
            $t->timestamps();
        });

        Schema::create('tr_statement_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('account_id')->index();
            $t->foreignId('statement_id')->index();
            $t->date('date');
            $t->string('label', 500);
            $t->decimal('amount', 15, 2);
            $t->decimal('balance', 15, 2)->nullable();
            $t->string('reference', 120)->nullable();
            $t->string('hash', 64);
            $t->string('status', 10)->default('open'); // open | matched | ignored
            $t->string('match_type', 20)->nullable(); // payment | operation
            $t->uuid('match_id')->nullable()->index();
            $t->timestamp('matched_at')->nullable();
            $t->string('matched_by', 10)->nullable(); // auto | manual
            $t->timestamps();
            $t->unique(['account_id', 'hash']);
        });

        Schema::table('payments', function (Blueprint $t) {
            $t->uuid('treasury_account_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('treasury_account_id'));
        foreach (['tr_statement_lines', 'tr_statements', 'tr_forecasts', 'tr_operations', 'tr_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
