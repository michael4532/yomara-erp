<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ab_subscriptions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->uuid('partner_id')->index();
            $t->string('name');
            $t->string('status', 10)->default('active'); // active, paused, ended
            $t->date('start_date');
            $t->date('next_date');
            $t->string('interval_unit', 6)->default('month'); // week, month, year
            $t->unsignedSmallInteger('interval_count')->default(1);
            $t->date('end_date')->nullable();
            $t->unsignedSmallInteger('max_invoices')->nullable();
            $t->unsignedSmallInteger('generated_count')->default(0);
            $t->json('lines');
            $t->char('currency', 3)->nullable();
            $t->boolean('auto_validate')->default(true);
            $t->boolean('auto_send')->default(false);
            $t->boolean('in_advance')->default(true); // facturé en début de période
            $t->json('analytic')->nullable();
            $t->text('notes')->nullable();
            $t->string('last_error')->nullable();
            $t->uuid('last_invoice_id')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status', 'next_date']);
        });

        Schema::create('ab_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id');
            $t->uuid('subscription_id')->index();
            $t->uuid('document_id');
            $t->date('period_start');
            $t->date('period_end');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ab_invoices');
        Schema::dropIfExists('ab_subscriptions');
    }
};
