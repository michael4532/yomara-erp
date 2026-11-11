<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mode de règlement prévu, compte bancaire affiché et case « Pour décharge » sur les documents de vente et d'achat. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_documents', function (Blueprint $t) {
            if (! Schema::hasColumn('trade_documents', 'payment_method')) {
                $t->string('payment_method', 30)->nullable();
            }
            if (! Schema::hasColumn('trade_documents', 'receipt_box')) {
                $t->boolean('receipt_box')->nullable();
            }
            if (! Schema::hasColumn('trade_documents', 'bank_account')) {
                $t->string('bank_account', 160)->nullable();
            }
            if (! Schema::hasColumn('trade_documents', 'template')) {
                $t->string('template', 60)->nullable(); // modèle de document (bibliothèque ou studio)
            }
        });
        // Ligne de déduction d'un acompte sur la facture finale : référence de la facture d'acompte
        Schema::table('trade_document_lines', function (Blueprint $t) {
            if (! Schema::hasColumn('trade_document_lines', 'deposit_id')) {
                $t->uuid('deposit_id')->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('trade_documents', function (Blueprint $t) {
            $t->dropColumn(['payment_method', 'receipt_box', 'bank_account', 'template']);
        });
        Schema::table('trade_document_lines', fn (Blueprint $t) => $t->dropColumn('deposit_id'));
    }
};
