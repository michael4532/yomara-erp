<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_lots', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('product_id')->index();
            $t->string('number', 80);
            $t->date('expiry_date')->nullable();
            $t->string('supplier_lot', 80)->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('alerted_at')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'product_id', 'number']);
        });
        Schema::table('products', function (Blueprint $t) {
            $t->string('tracking', 10)->default('none'); // none | lot | serial
            $t->boolean('use_expiry')->default(false);
            $t->unsignedSmallInteger('shelf_life_days')->nullable();
            $t->unsignedSmallInteger('expiry_alert_days')->nullable();
        });
        Schema::table('stock_moves', fn (Blueprint $t) => $t->uuid('lot_id')->nullable()->index());
        Schema::table('trade_document_lines', fn (Blueprint $t) => $t->json('lots')->nullable());
    }

    public function down(): void
    {
        Schema::table('trade_document_lines', fn (Blueprint $t) => $t->dropColumn('lots'));
        Schema::table('stock_moves', fn (Blueprint $t) => $t->dropColumn('lot_id'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['tracking', 'use_expiry', 'shelf_life_days', 'expiry_alert_days']));
        Schema::dropIfExists('stock_lots');
    }
};
