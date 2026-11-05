<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $base = function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
        };
        $end = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };

        Schema::create('rs_floors', function (Blueprint $t) use ($base, $end) {
            $base($t);
            $t->string('name');
            $t->unsignedInteger('sort')->default(0);
            $end($t);
        });

        Schema::create('rs_tables', function (Blueprint $t) use ($base, $end) {
            $base($t);
            $t->uuid('floor_id')->nullable()->index();
            $t->string('name', 40);
            $t->unsignedSmallInteger('seats')->default(4);
            $t->string('shape', 10)->default('square'); // square | round | rect
            $t->unsignedSmallInteger('x')->default(0); // position sur une grille de 24 × 16
            $t->unsignedSmallInteger('y')->default(0);
            $t->unsignedSmallInteger('w')->default(2);
            $t->unsignedSmallInteger('h')->default(2);
            $t->boolean('active')->default(true);
            $end($t);
        });

        Schema::create('rs_stations', function (Blueprint $t) use ($base, $end) {
            $base($t);
            $t->string('name');
            $t->string('kind', 10)->default('kitchen'); // kitchen | bar
            $t->json('category_ids')->nullable(); // catégories de produits envoyées à ce poste
            $t->unsignedInteger('sort')->default(0);
            $end($t);
        });

        Schema::create('rs_orders', function (Blueprint $t) use ($base, $end) {
            $base($t);
            $t->string('number', 40)->nullable();
            $t->string('kind', 12)->default('dine_in'); // dine_in | takeaway
            $t->uuid('table_id')->nullable()->index();
            $t->string('status', 12)->default('open'); // open | closed | cancelled
            $t->unsignedSmallInteger('guests')->default(1);
            $t->foreignId('waiter_id')->nullable();
            $t->string('waiter_name')->nullable();
            $t->string('customer_name')->nullable();
            $t->uuid('partner_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('bill_requested_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $end($t);
            $t->index(['company_id', 'status']);
        });

        Schema::create('rs_order_lines', function (Blueprint $t) use ($base, $end) {
            $base($t);
            $t->uuid('order_id')->index();
            $t->uuid('product_id')->nullable();
            $t->string('description');
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('unit_price', 15, 2)->default(0); // HT
            $t->decimal('tax_rate', 6, 2)->default(0);
            $t->unsignedTinyInteger('course')->default(1); // 1 entrées, 2 plats, 3 desserts, 0 boissons / sans suite
            $t->string('note')->nullable(); // cuisson, sans oignons…
            $t->uuid('station_id')->nullable()->index();
            $t->string('status', 12)->default('pending'); // pending | sent | preparing | ready | served | cancelled
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('ready_at')->nullable();
            $t->timestamp('served_at')->nullable();
            $t->string('cancel_reason')->nullable();
            $t->foreignId('user_id')->nullable();
            $end($t);
        });

        // Ticket de caisse d'une addition : commande et quantités réglées par ligne {ligne: qté}
        Schema::table('trade_documents', function (Blueprint $t) {
            $t->uuid('rs_order_id')->nullable()->index();
            $t->json('rs_bill')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn(['rs_order_id', 'rs_bill']));
        foreach (['rs_order_lines', 'rs_orders', 'rs_stations', 'rs_tables', 'rs_floors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
