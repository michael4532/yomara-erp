<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pr_packs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->uuid('partner_id')->index();
            $t->string('partner_name')->nullable();
            $t->string('name');
            $t->decimal('hours', 10, 2);
            $t->decimal('price', 14, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->decimal('hourly_rate', 14, 2)->nullable();
            $t->date('start_date');
            $t->date('expiry_date')->nullable();
            $t->string('status', 10)->default('active'); // active, exhausted, expired, cancelled
            $t->unsignedTinyInteger('alert_percent')->default(80);
            $t->string('alerted', 20)->nullable();
            $t->uuid('invoice_id')->nullable();
            $t->decimal('overage_billed', 10, 2)->default(0);
            $t->json('overage_invoices')->nullable();
            $t->uuid('renewed_from')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pr_entries', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('pack_id')->index();
            $t->date('date');
            $t->decimal('hours', 8, 2);
            $t->string('description');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('user_name', 120)->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('pr_deliverables', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->uuid('partner_id')->index();
            $t->string('partner_name')->nullable();
            $t->uuid('pack_id')->nullable();
            $t->uuid('project_id')->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->string('url', 500)->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->unsignedBigInteger('file_size')->nullable();
            $t->unsignedSmallInteger('version_no')->default(1);
            $t->string('status', 10)->default('draft'); // draft, sent, approved, changes
            $t->date('due_date')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decided_by', 120)->nullable();
            $t->text('client_comment')->nullable();
            $t->json('history')->nullable();
            $t->string('token', 64)->nullable()->unique();
            $t->boolean('reminded')->default(false);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_deliverables');
        Schema::dropIfExists('pr_entries');
        Schema::dropIfExists('pr_packs');
    }
};
