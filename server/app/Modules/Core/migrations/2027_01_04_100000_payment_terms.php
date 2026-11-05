<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Conditions de paiement : une ou plusieurs échéances (pourcentage, jours, fin de mois)
        Schema::create('payment_terms', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 120);
            $t->json('lines');
            $t->boolean('is_default_sale')->default(false);
            $t->boolean('is_default_purchase')->default(false);
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::table('partners', function (Blueprint $t) {
            $t->uuid('payment_term_id')->nullable();          // en tant que client
            $t->uuid('supplier_payment_term_id')->nullable(); // en tant que fournisseur
        });
        Schema::table('trade_documents', function (Blueprint $t) {
            $t->uuid('payment_term_id')->nullable();
            $t->json('schedule')->nullable(); // échéancier calculé à la validation
        });
    }

    public function down(): void
    {
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn(['payment_term_id', 'schedule']));
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn(['payment_term_id', 'supplier_payment_term_id']));
        Schema::dropIfExists('payment_terms');
    }
};
