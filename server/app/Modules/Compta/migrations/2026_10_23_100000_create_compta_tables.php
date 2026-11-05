<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compta_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('code', 20);
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('compta_journals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('code', 10);
            $table->string('name');
            $table->string('type', 20); // sale, purchase, cash, bank, mobile_money, misc
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('compta_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('journal_id');
            $table->string('number', 30);
            $table->date('date');
            $table->string('label');
            $table->string('reference', 100)->nullable();
            $table->string('source_type', 40)->nullable(); // document | payment | manual
            $table->string('source_id', 64)->nullable();
            $table->string('kind', 30)->default('manual'); // doc, payment, manual, reversal
            $table->foreignId('reverses_id')->nullable();   // écriture contre-passée
            $table->foreignId('reversed_by_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();
            $table->index(['company_id', 'date']);
            $table->index(['company_id', 'source_type', 'source_id']);
        });

        Schema::create('compta_entry_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->index();
            $table->foreignId('company_id');
            $table->foreignId('account_id');
            $table->uuid('partner_id')->nullable();
            $table->string('label')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->index(['company_id', 'account_id']);
        });
    }

    public function down(): void
    {
        foreach (['compta_entry_lines', 'compta_entries', 'compta_journals', 'compta_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
