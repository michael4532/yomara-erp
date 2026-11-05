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
        Schema::create('sp_accesses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('partner_id')->index();
            $t->string('token', 48)->unique();
            $t->string('email')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamp('last_seen_at')->nullable();
            $t->string('invited_by', 120)->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'partner_id']);
        });
        Schema::create('sp_tenders', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->json('lines');
            $t->timestamp('deadline');
            $t->string('status', 10)->default('draft'); // draft, open, closed, awarded, cancelled
            $t->json('invited');
            $t->string('currency', 3)->nullable();
            $t->string('delivery_place')->nullable();
            $t->uuid('awarded_partner_id')->nullable();
            $t->uuid('order_id')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->string('created_by', 120)->nullable();
            $sync($t);
        });
        Schema::create('sp_bids', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('tender_id')->index();
            $t->uuid('partner_id');
            $t->string('partner_name')->nullable();
            $t->json('lines');
            $t->decimal('total', 16, 2)->default(0);
            $t->unsignedSmallInteger('delivery_days')->nullable();
            $t->date('valid_until')->nullable();
            $t->text('comment')->nullable();
            $t->string('status', 10)->default('submitted'); // submitted, awarded, lost
            $t->timestamp('submitted_at')->nullable();
            $t->unsignedSmallInteger('revisions')->default(0);
            $sync($t);
            $t->unique(['tender_id', 'partner_id']);
        });
        Schema::create('sp_order_acks', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('order_id')->index();
            $t->uuid('partner_id');
            $t->string('status', 10); // accepted, declined
            $t->date('delivery_date')->nullable();
            $t->text('comment')->nullable();
            $t->string('by_name', 120)->nullable();
            $sync($t);
        });
        Schema::create('sp_invoices', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('partner_id')->index();
            $t->string('partner_name')->nullable();
            $t->uuid('order_id')->nullable();
            $t->string('number', 60);
            $t->date('date');
            $t->decimal('amount', 16, 2);
            $t->string('currency', 3)->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->string('status', 10)->default('received'); // received, accepted, rejected
            $t->uuid('supplier_invoice_id')->nullable();
            $t->text('reject_reason')->nullable();
            $t->text('comment')->nullable();
            $sync($t);
        });
    }

    public function down(): void
    {
        foreach (['sp_invoices', 'sp_order_acks', 'sp_bids', 'sp_tenders', 'sp_accesses'] as $tb) {
            Schema::dropIfExists($tb);
        }
    }
};
