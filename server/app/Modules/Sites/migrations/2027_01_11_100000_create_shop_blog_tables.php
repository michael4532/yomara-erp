<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ws_orders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id')->index();
            $t->string('number', 30);
            $t->string('token', 48)->unique();
            $t->string('name', 160);
            $t->string('phone', 40);
            $t->string('email')->nullable();
            $t->string('address', 300)->nullable();
            $t->string('delivery_mode', 120);
            $t->decimal('delivery_fee', 14, 2)->default(0);
            $t->string('payment_mode', 10); // online, cod, momo
            $t->json('items');
            $t->decimal('subtotal', 14, 2)->default(0);
            $t->decimal('total', 14, 2)->default(0);
            $t->string('status', 12)->default('new'); // new, confirmed, preparing, shipped, delivered, cancelled
            $t->string('payment_status', 10)->default('unpaid');
            $t->uuid('document_id')->nullable();
            $t->uuid('partner_id')->nullable();
            $t->text('note')->nullable();
            $t->text('staff_note')->nullable();
            $t->timestamps();
        });
        Schema::create('ws_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id')->index();
            $t->string('title', 200);
            $t->string('slug', 120);
            $t->string('excerpt', 400)->nullable();
            $t->longText('body')->nullable();
            $t->string('cover_path')->nullable();
            $t->boolean('published')->default(false);
            $t->timestamp('published_at')->nullable();
            $t->unsignedInteger('views')->default(0);
            $t->timestamps();
            $t->unique(['site_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ws_posts');
        Schema::dropIfExists('ws_orders');
    }
};
