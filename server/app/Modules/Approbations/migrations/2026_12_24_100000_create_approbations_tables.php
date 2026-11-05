<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wf_rules', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 150);
            $t->json('targets');                         // sale.quote, purchase.purchase_order, expense…
            $t->decimal('min_amount', 18, 2)->nullable(); // HT, monnaie de la société
            $t->decimal('max_amount', 18, 2)->nullable();
            $t->decimal('discount_over', 5, 2)->nullable(); // remise de ligne supérieure à (%)
            $t->json('partner_ids')->nullable();
            $t->json('category_ids')->nullable();        // catégories de notes de frais
            $t->json('steps');                           // [{name, mode, roles, users, escalate_after_hours, escalate_roles}]
            $t->boolean('auto_validate')->default(true);
            $t->unsignedSmallInteger('reminder_hours')->default(24);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('wf_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('rule_id')->nullable();
            $t->string('rule_name', 150);
            $t->string('target', 40);
            $t->uuid('record_id')->index();
            $t->string('label');
            $t->decimal('amount', 18, 2)->default(0);
            $t->decimal('discount', 5, 2)->default(0);
            $t->string('currency', 3);
            $t->foreignId('requested_by')->nullable();
            $t->string('status', 12)->default('pending'); // pending | approved | rejected | cancelled
            $t->json('steps');
            $t->boolean('auto_validate')->default(true);
            $t->unsignedSmallInteger('reminder_hours')->default(24);
            $t->unsignedTinyInteger('current_step')->default(0);
            $t->timestamp('step_started_at')->nullable();
            $t->timestamp('last_reminded_at')->nullable();
            $t->timestamp('escalated_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('note')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status']);
        });

        Schema::create('wf_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('request_id')->index();
            $t->unsignedTinyInteger('step');
            $t->foreignId('user_id')->nullable();
            $t->foreignId('on_behalf_of')->nullable();
            $t->string('decision', 12); // submitted | approved | rejected | implicit | cancelled | escalated
            $t->text('comment')->nullable();
            $t->timestamps();
        });

        Schema::create('wf_delegations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id');
            $t->foreignId('delegate_id');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('note')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wf_delegations');
        Schema::dropIfExists('wf_decisions');
        Schema::dropIfExists('wf_requests');
        Schema::dropIfExists('wf_rules');
    }
};
