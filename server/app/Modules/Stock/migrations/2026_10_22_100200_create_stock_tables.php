<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // Journal des mouvements (ajout seulement) : le stock = somme des quantités.
        Schema::create('stock_moves', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('product_id');
            $table->uuid('warehouse_id');
            $table->decimal('quantity', 15, 3); // + entrée / − sortie
            $table->string('type', 30); // initial, purchase, sale, return, adjustment, transfer_in, transfer_out
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->uuid('document_id')->nullable()->index();
            $table->string('reference', 100)->nullable();
            $table->date('date');
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'product_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_moves');
        Schema::dropIfExists('warehouses');
    }
};
