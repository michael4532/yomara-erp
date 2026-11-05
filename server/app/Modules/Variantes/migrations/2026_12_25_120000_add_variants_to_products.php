<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            $t->uuid('parent_id')->nullable()->index();   // variante : son modèle
            $t->boolean('is_template')->default(false);   // modèle (non vendu, non stocké)
            $t->json('variant_attributes')->nullable();   // modèle : [{name, values: [...]}]
            $t->json('price_extras')->nullable();         // modèle : {"Taille": {"XL": 500}}
            $t->json('variant_values')->nullable();       // variante : {"Taille": "M", "Couleur": "Rouge"}
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn(['parent_id', 'is_template', 'variant_attributes', 'price_extras', 'variant_values']));
    }
};
