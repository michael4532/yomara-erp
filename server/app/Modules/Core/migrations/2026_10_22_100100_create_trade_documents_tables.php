<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents commerciaux partagés par les modules Ventes et Achats.
 * direction = sale | purchase
 * type : quote, order, invoice, credit_note (ventes) / rfq, purchase_order, supplier_invoice (achats)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('direction', 10);
            $table->string('type', 30);
            $table->string('number', 50)->nullable();   // attribué par le serveur à la validation
            $table->string('reference', 100)->nullable();
            $table->uuid('partner_id')->nullable()->index();
            $table->string('partner_name')->nullable();
            $table->date('date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft'); // draft, validated, cancelled
            $table->string('payment_status', 20)->default('unpaid'); // unpaid, partial, paid
            $table->string('currency', 3)->default('XOF');
            $table->uuid('warehouse_id')->nullable();
            $table->uuid('source_id')->nullable(); // document d'origine (devis → facture…)
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->boolean('stock_posted')->default(false);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamp('validated_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'direction', 'type', 'status']);
            $table->index(['company_id', 'date']);
        });

        Schema::create('trade_document_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id')->index();
            $table->uuid('product_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 15, 3)->default(1);
            $table->string('unit', 30)->nullable();
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->unsignedInteger('sort')->default(0);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('direction', 10); // sale = encaissement, purchase = décaissement
            $table->uuid('document_id')->index();
            $table->uuid('partner_id')->nullable();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('method', 30)->default('cash'); // cash, mobile_money, card, bank_transfer, cheque
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('trade_document_lines');
        Schema::dropIfExists('trade_documents');
    }
};
