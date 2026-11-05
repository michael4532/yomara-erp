<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Dossiers de recouvrement : factures en contentieux, actions, promesses, échéancier négocié. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rc_cases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('number', 30)->nullable();
            $t->uuid('partner_id')->index();
            $t->string('status', 14)->default('open')->index();
            $t->unsignedBigInteger('assigned_to')->nullable()->index();
            $t->date('opened_at');
            $t->date('next_action_at')->nullable();
            $t->decimal('amount_initial', 16, 2)->default(0);
            $t->decimal('recovered', 16, 2)->default(0);
            $t->decimal('fees', 16, 2)->default(0);
            $t->decimal('written_off', 16, 2)->default(0);
            $t->string('bailiff', 160)->nullable();
            $t->date('notice_deadline')->nullable();
            $t->text('note')->nullable();
            $t->boolean('dunning_was_paused')->default(false);
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('rc_case_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('case_id')->index();
            $t->uuid('document_id');
            $t->decimal('amount_at_open', 16, 2)->default(0);
            $t->unique(['case_id', 'document_id']);
        });
        Schema::create('rc_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('case_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type', 14);
            $t->date('date');
            $t->text('body')->nullable();
            $t->decimal('amount', 16, 2)->nullable();
            $t->date('promise_date')->nullable();
            $t->string('promise_status', 10)->nullable(); // pending, kept, broken
            $t->json('data')->nullable();
            $t->timestamps();
        });
        Schema::create('rc_installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('case_id')->index();
            $t->date('due_date');
            $t->decimal('amount', 16, 2);
            $t->decimal('paid', 16, 2)->default(0);
            $t->boolean('alerted')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rc_installments');
        Schema::dropIfExists('rc_actions');
        Schema::dropIfExists('rc_case_invoices');
        Schema::dropIfExists('rc_cases');
    }
};
