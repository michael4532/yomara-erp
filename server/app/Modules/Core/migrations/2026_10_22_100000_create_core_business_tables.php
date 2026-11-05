<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('type', 20)->default('customer'); // customer | supplier | both
            $table->boolean('is_company')->default(true);
            $table->string('name');
            $table->string('code', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('country', 2)->nullable();
            $table->string('tax_id', 100)->nullable();
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'name']);
        });

        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('category_id')->nullable()->index();
            $table->string('type', 20)->default('product'); // product | service
            $table->string('sku', 100)->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('unit', 30)->default('unité');
            $table->decimal('sale_price', 15, 2)->default(0);
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(18);
            $table->boolean('track_stock')->default(true);
            $table->decimal('min_stock', 15, 3)->default(0);
            $table->string('image')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'name']);
            $table->index(['company_id', 'barcode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('partners');
    }
};
