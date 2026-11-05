<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ws_coupons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id')->index();
            $t->string('code', 40);
            $t->string('type', 12); // percent, amount, shipping
            $t->decimal('value', 14, 2)->default(0);
            $t->decimal('min_order', 14, 2)->default(0);
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->unsignedInteger('max_uses')->nullable();
            $t->unsignedInteger('uses')->default(0);
            $t->boolean('once_per_customer')->default(false);
            $t->boolean('active')->default(true);
            $t->string('description', 200)->nullable();
            $t->timestamps();
            $t->unique(['site_id', 'code']);
        });
        Schema::table('ws_orders', function (Blueprint $t) {
            $t->string('coupon_code', 40)->nullable();
            $t->decimal('discount', 14, 2)->default(0);
            $t->timestamp('review_requested_at')->nullable();
        });
        Schema::create('ws_reviews', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id')->index();
            $t->unsignedBigInteger('order_id')->unique();
            $t->string('name', 160);
            $t->unsignedTinyInteger('rating');
            $t->text('comment')->nullable();
            $t->json('products')->nullable(); // {product_id: note}
            $t->string('status', 10)->default('published'); // pending, published, hidden
            $t->text('reply')->nullable();
            $t->timestamp('replied_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ws_carts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id')->index();
            $t->string('token', 40)->unique();
            $t->string('name', 160)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->json('items');
            $t->decimal('total', 14, 2)->default(0);
            $t->unsignedBigInteger('order_id')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->string('reminder_channel', 10)->nullable();
            $t->timestamp('recovered_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ws_carts');
        Schema::dropIfExists('ws_reviews');
        Schema::table('ws_orders', fn (Blueprint $t) => $t->dropColumn(['coupon_code', 'discount', 'review_requested_at']));
        Schema::dropIfExists('ws_coupons');
    }
};
