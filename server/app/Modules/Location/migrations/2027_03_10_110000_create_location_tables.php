<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $base = function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable()->index();
        };
        Schema::create('lo_items', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('category', 80)->nullable();
            $t->string('reference', 80)->nullable();
            $t->unsignedInteger('quantity')->default(1);
            $t->decimal('rate_day', 14, 2)->default(0);
            $t->decimal('rate_week', 14, 2)->nullable();
            $t->decimal('rate_month', 14, 2)->nullable();
            $t->decimal('deposit', 14, 2)->nullable();
            $t->decimal('replacement_value', 14, 2)->nullable();
            $t->string('status', 12)->default('available');
            $t->string('location', 120)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('lo_rentals', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('number', 30)->nullable()->index();
            $t->string('partner_id', 40)->nullable()->index();
            $t->string('customer_name', 200)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('status', 12)->default('quote')->index();
            $t->dateTime('start_at')->index();
            $t->dateTime('end_at')->index();
            $t->dateTime('out_at')->nullable();
            $t->dateTime('returned_at')->nullable();
            $t->boolean('delivery')->default(false);
            $t->string('delivery_address', 255)->nullable();
            $t->decimal('delivery_fee', 14, 2)->nullable();
            $t->decimal('discount_percent', 5, 2)->nullable();
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->decimal('deposit_amount', 14, 2)->nullable();
            $t->string('deposit_status', 12)->default('none');
            $t->string('deposit_method', 12)->nullable();
            $t->decimal('deposit_kept', 14, 2)->nullable();
            $t->decimal('late_fee', 14, 2)->nullable();
            $t->decimal('damage_fee', 14, 2)->nullable();
            $t->decimal('total', 16, 2)->default(0);
            $t->string('invoice_id', 40)->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('reminded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('lo_lines', function (Blueprint $t) use ($base) {
            $base($t);
            $t->unsignedBigInteger('rental_id')->index();
            $t->unsignedBigInteger('item_id')->index();
            $t->unsignedInteger('quantity')->default(1);
            $t->string('pricing', 8)->default('day');
            $t->decimal('unit_price', 14, 2)->nullable();
            $t->decimal('amount', 16, 2)->default(0);
            $t->string('condition_out', 200)->nullable();
            $t->string('condition_in', 200)->nullable();
            $t->decimal('damage_fee', 14, 2)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['lo_lines', 'lo_rentals', 'lo_items'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
