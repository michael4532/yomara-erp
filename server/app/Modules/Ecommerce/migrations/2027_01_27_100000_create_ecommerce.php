<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ec_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique();
            $t->string('feed_token', 40)->unique();
            $t->boolean('reserve_stock')->default(true);
            $t->boolean('auto_delivery')->default(true);
            $t->boolean('notify_customer')->default(true);
            $t->unsignedSmallInteger('alert_hours')->default(24);
            $t->string('whatsapp_number', 30)->nullable();
            $t->string('brand', 120)->nullable();
            $t->timestamps();
        });
        Schema::table('ws_orders', function (Blueprint $t) {
            $t->string('channel', 12)->default('site');
            $t->uuid('invoice_id')->nullable()->index();
            $t->uuid('delivery_id')->nullable()->index();
            $t->string('payment_method', 20)->nullable();
            $t->string('payment_ref', 80)->nullable();
            $t->timestamp('confirmed_at')->nullable();
            $t->timestamp('shipped_at')->nullable();
            $t->timestamp('delivered_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ws_orders', function (Blueprint $t) {
            $t->dropColumn(['channel', 'invoice_id', 'delivery_id', 'payment_method', 'payment_ref', 'confirmed_at', 'shipped_at', 'delivered_at', 'cancelled_at']);
        });
        Schema::dropIfExists('ec_settings');
    }
};
