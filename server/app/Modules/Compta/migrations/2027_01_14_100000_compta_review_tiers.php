<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Circuit comptable des factures fournisseurs : à revoir → approuvée / refusée → comptabilisée
        Schema::table('trade_documents', function (Blueprint $t) {
            $t->string('acc_status', 12)->nullable()->index();
            $t->text('acc_note')->nullable();
            $t->unsignedBigInteger('acc_reviewed_by')->nullable();
            $t->timestamp('acc_reviewed_at')->nullable();
        });
        // Factures fournisseurs déjà passées en comptabilité
        \Illuminate\Support\Facades\DB::table('trade_documents')->where('direction', 'purchase')->where('type', 'supplier_invoice')
            ->whereIn('id', \Illuminate\Support\Facades\DB::table('compta_entries')->where('source_type', 'document')->whereNull('reversed_by_id')->select('source_id'))
            ->update(['acc_status' => 'posted']);
        // Nature d'achat (compte de charge ou d'immobilisation) choisie ligne par ligne
        Schema::table('trade_document_lines', fn (Blueprint $t) => $t->string('account_code', 12)->nullable());
        // Compte auxiliaire des tiers (CLI00001, FRS00001)
        Schema::table('partners', fn (Blueprint $t) => $t->string('aux_code', 20)->nullable()->index());
        // Comptes mémorisés : article, fournisseur ou catégorie → compte d'achat
        Schema::create('compta_account_rules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('scope', 12); // product, supplier, category
            $t->string('ref_id', 40);
            $t->string('account_code', 12);
            $t->timestamps();
            $t->unique(['company_id', 'scope', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compta_account_rules');
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn('aux_code'));
        Schema::table('trade_document_lines', fn (Blueprint $t) => $t->dropColumn('account_code'));
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn(['acc_status', 'acc_note', 'acc_reviewed_by', 'acc_reviewed_at']));
    }
};
