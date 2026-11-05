<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lb_templates', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->string('kind', 10)->default('sheet'); // sheet (planche A4) | roll (imprimante d'étiquettes)
            $t->decimal('page_w', 6, 1)->default(210); // mm
            $t->decimal('page_h', 6, 1)->default(297);
            $t->unsignedSmallInteger('cols')->default(3);
            $t->unsignedSmallInteger('rows')->default(8);
            $t->decimal('label_w', 6, 1)->default(70);
            $t->decimal('label_h', 6, 1)->default(37);
            $t->decimal('margin_top', 6, 1)->default(0);
            $t->decimal('margin_left', 6, 1)->default(0);
            $t->decimal('gap_x', 6, 1)->default(0);
            $t->decimal('gap_y', 6, 1)->default(0);
            $t->string('style', 12)->default('product'); // product | shelf (prix en grand) | barcode
            $t->json('fields')->nullable(); // name, price, barcode, sku, unit_price, company, date
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        // Dernière impression par produit (id = id du produit) : prix imprimé, pour savoir quoi réimprimer
        Schema::create('lb_prints', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('product_id')->index();
            $t->decimal('price', 15, 2)->default(0); // prix TTC imprimé
            $t->unsignedInteger('total')->default(0);
            $t->timestamp('printed_at')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });

        // Code balance (PLU à 5 chiffres) des articles vendus au poids
        Schema::table('products', function (Blueprint $t) {
            $t->string('plu', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('plu'));
        Schema::dropIfExists('lb_prints');
        Schema::dropIfExists('lb_templates');
    }
};
