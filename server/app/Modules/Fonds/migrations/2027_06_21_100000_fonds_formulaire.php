<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Formulaire d'appel de fonds complet : motif, émetteur et bénéficiaire, remise et taxes, modalités de paiement,
 * pièces jointes, cadre de la comptabilité et remarques (modèle personnalisable par société).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('af_requests', function (Blueprint $t) {
            if (Schema::hasColumn('af_requests', 'motive')) {
                return;
            }
            $t->string('motive', 60)->nullable();
            $t->string('motive_other', 120)->nullable();
            $t->string('requester_function', 120)->nullable();
            $t->string('project_label', 160)->nullable();
            $t->string('beneficiary', 160)->nullable();
            $t->string('beneficiary_structure', 160)->nullable();
            $t->string('payment_method', 60)->nullable();
            $t->string('payment_other', 120)->nullable();
            $t->json('attachments')->nullable();          // pièces jointes cochées
            $t->string('attachments_other', 120)->nullable();
            $t->string('discount_mode', 10)->default('percent'); // percent | amount
            $t->decimal('discount_value', 16, 2)->default(0);
            $t->json('taxes')->nullable();                // [{label, rate, base: net|gross}]
            $t->decimal('total_gross', 16, 2)->default(0);
            $t->decimal('total_discount', 16, 2)->default(0);
            $t->decimal('total_taxes', 16, 2)->default(0);
            $t->boolean('budget_available')->nullable();
            $t->boolean('documents_compliant')->nullable();
            $t->text('accounting_note')->nullable();
            $t->text('remarks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('af_requests', fn (Blueprint $t) => $t->dropColumn(['motive', 'motive_other', 'requester_function', 'project_label', 'beneficiary', 'beneficiary_structure',
            'payment_method', 'payment_other', 'attachments', 'attachments_other', 'discount_mode', 'discount_value', 'taxes', 'total_gross', 'total_discount', 'total_taxes',
            'budget_available', 'documents_compliant', 'accounting_note', 'remarks']));
    }
};
