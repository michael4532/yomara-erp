<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ev_events', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->string('venue')->nullable();
            $t->string('city', 120)->nullable();
            $t->date('start_date');
            $t->date('end_date');
            $t->string('status', 12)->default('draft'); // draft, open, closed, done, cancelled
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('plan_cols')->default(24);
            $t->unsignedSmallInteger('plan_rows')->default(14);
            $t->json('categories')->nullable(); // [{name, color, price, area}]
            $t->char('currency', 3)->nullable();
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->decimal('deposit_percent', 5, 2)->default(50);
            $t->unsignedSmallInteger('balance_days')->default(15);
            $t->unsignedSmallInteger('option_days')->default(7);
            $t->boolean('public_enabled')->default(false);
            $t->string('public_token', 64)->nullable()->unique();
            $t->text('terms')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('ev_spaces', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('event_id')->index();
            $t->string('code', 20);
            $t->string('category', 80)->nullable();
            $t->string('zone', 80)->nullable();
            $t->decimal('area', 8, 2)->nullable();
            $t->decimal('price', 14, 2)->default(0);
            $t->unsignedSmallInteger('x')->default(0);
            $t->unsignedSmallInteger('y')->default(0);
            $t->unsignedSmallInteger('w')->default(1);
            $t->unsignedSmallInteger('h')->default(1);
            $t->string('features')->nullable();
            $t->boolean('blocked')->default(false);
            $t->string('notes')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('ev_packages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('event_id')->index();
            $t->string('kind', 10)->default('option'); // sponsor, option
            $t->string('name');
            $t->text('description')->nullable();
            $t->decimal('price', 14, 2)->default(0);
            $t->unsignedInteger('quantity')->nullable(); // null = illimité
            $t->json('benefits')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->boolean('online')->default(true);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('ev_bookings', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->uuid('event_id')->index();
            $t->uuid('partner_id')->nullable()->index();
            $t->string('partner_name')->nullable();
            $t->string('exhibitor_name')->nullable();
            $t->string('contact_name', 120)->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->string('contact_email')->nullable();
            $t->string('status', 10)->default('option'); // option, confirmed, cancelled, expired
            $t->date('option_until')->nullable();
            $t->json('space_ids')->nullable();
            $t->json('items')->nullable();
            $t->decimal('discount_percent', 5, 2)->default(0);
            $t->string('source', 10)->default('manual');
            $t->text('notes')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('checked_in_at')->nullable();
            $t->uuid('deposit_invoice_id')->nullable();
            $t->uuid('balance_invoice_id')->nullable();
            $t->string('last_error')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'event_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ev_bookings');
        Schema::dropIfExists('ev_packages');
        Schema::dropIfExists('ev_spaces');
        Schema::dropIfExists('ev_events');
    }
};
