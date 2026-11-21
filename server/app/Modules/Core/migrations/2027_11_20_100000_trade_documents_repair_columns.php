<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rattrapage : sur les serveurs où la migration du 21/06 avait déjà tourné avant l'ajout de
 * « compte bancaire » et « modèle », ces colonnes manquaient (erreur à l'enregistrement des factures).
 */
return new class extends Migration
{
    public function up(): void
    {
        $missing = [
            'payment_method' => fn (Blueprint $t) => $t->string('payment_method', 30)->nullable(),
            'receipt_box' => fn (Blueprint $t) => $t->boolean('receipt_box')->nullable(),
            'bank_account' => fn (Blueprint $t) => $t->string('bank_account', 160)->nullable(),
            'template' => fn (Blueprint $t) => $t->string('template', 60)->nullable(),
        ];
        foreach ($missing as $col => $add) {
            if (! Schema::hasColumn('trade_documents', $col)) {
                Schema::table('trade_documents', $add);
            }
        }
        if (! Schema::hasColumn('trade_document_lines', 'deposit_id')) {
            Schema::table('trade_document_lines', fn (Blueprint $t) => $t->uuid('deposit_id')->nullable()->index());
        }
    }

    public function down(): void {}
};
