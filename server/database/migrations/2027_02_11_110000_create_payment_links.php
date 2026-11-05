<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payment_gateways', 'treasury_account_id')) {
            // Compte de trésorerie qui reçoit les paiements de ce prestataire (ex. « Compte Wave »)
            Schema::table('payment_gateways', fn (Blueprint $t) => $t->uuid('treasury_account_id')->nullable());
        }
        Schema::create('pay_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('token', 40)->unique();
            $t->string('label', 160);
            $t->text('description')->nullable();
            $t->decimal('amount', 15, 2)->nullable(); // null : montant libre saisi par le payeur
            $t->decimal('min_amount', 15, 2)->nullable();
            $t->string('currency', 3)->nullable();
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->uuid('partner_id')->nullable();
            $t->string('customer_name', 160)->nullable();
            $t->string('customer_phone', 40)->nullable();
            $t->string('customer_email', 160)->nullable();
            $t->boolean('reusable')->default(false); // lien permanent (dons, acomptes, boutique Instagram…)
            $t->string('status', 10)->default('open'); // open, paid, cancelled
            $t->date('expires_on')->nullable();
            $t->unsignedInteger('payments_count')->default(0);
            $t->decimal('collected', 15, 2)->default(0);
            $t->uuid('last_document_id')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_links');
        if (Schema::hasColumn('payment_gateways', 'treasury_account_id')) {
            Schema::table('payment_gateways', fn (Blueprint $t) => $t->dropColumn('treasury_account_id'));
        }
    }
};
