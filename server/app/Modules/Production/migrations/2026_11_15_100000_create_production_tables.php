<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrp_boms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('product_id')->index(); // produit fini, plat, kit
            $t->string('name')->nullable();
            $t->string('kind', 12)->default('manufacture'); // manufacture (ordre de fabrication) | kit (recette consommée à la vente)
            $t->decimal('quantity', 12, 3)->default(1); // quantité obtenue
            $t->json('lines')->nullable(); // [{product_id, quantity, loss_percent, note}]
            $t->unsignedInteger('labour_minutes')->default(0);
            $t->decimal('extra_cost', 15, 2)->default(0); // énergie, emballage… par lot
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('mrp_orders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->uuid('bom_id')->nullable();
            $t->uuid('product_id')->index();
            $t->decimal('quantity', 12, 3);
            $t->decimal('produced_qty', 12, 3)->nullable();
            $t->uuid('warehouse_id')->nullable();
            $t->string('status', 12)->default('draft'); // draft | confirmed | in_progress | done | cancelled
            $t->date('planned_date')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->json('components')->nullable(); // [{product_id, description, planned, consumed, unit_cost}]
            $t->unsignedInteger('labour_minutes')->default(0);
            $t->decimal('material_cost', 15, 2)->nullable();
            $t->decimal('labour_cost', 15, 2)->nullable();
            $t->decimal('unit_cost', 15, 4)->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('user_name')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mrp_orders');
        Schema::dropIfExists('mrp_boms');
    }
};
