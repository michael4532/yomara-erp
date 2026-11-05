<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_lists', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 120);
            $t->boolean('is_default')->default(false);
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->json('items')->nullable(); // [{scope: product|category|all, product_id, category_id, min_qty, mode: price|discount, price, discount}]
            $t->text('notes')->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::table('partners', fn (Blueprint $t) => $t->uuid('price_list_id')->nullable());
        Schema::table('products', fn (Blueprint $t) => $t->json('packagings')->nullable()); // [{name, factor, barcode, sale_price, purchase_price}]
        Schema::table('trade_document_lines', fn (Blueprint $t) => $t->decimal('unit_factor', 12, 4)->default(1));
    }

    public function down(): void
    {
        Schema::table('trade_document_lines', fn (Blueprint $t) => $t->dropColumn('unit_factor'));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('packagings'));
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn('price_list_id'));
        Schema::dropIfExists('pl_lists');
    }
};
