<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fne_configs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique();
            $t->boolean('enabled')->default(false);
            $t->string('environment', 10)->default('test'); // test | prod
            $t->string('base_url')->nullable();             // URL de production communiquée par la DGI
            $t->text('api_key')->nullable();                // chiffrée
            $t->string('point_of_sale', 100)->nullable();
            $t->string('establishment', 100)->nullable();
            $t->string('default_template', 3)->default('B2C');
            $t->string('zero_tax_code', 4)->default('TVAD'); // TVAC (exonération conventionnelle) | TVAD (légale)
            $t->boolean('auto_certify')->default(true);
            $t->boolean('certify_receipts')->default(true);
            $t->boolean('certify_credit_notes')->default(true);
            $t->string('commercial_message')->nullable();
            $t->string('footer')->nullable();
            $t->unsignedInteger('sticker_alert')->default(100);
            $t->integer('sticker_balance')->nullable();
            $t->timestamp('sticker_balance_at')->nullable();
            $t->timestamp('sticker_alerted_at')->nullable();
            $t->timestamps();
        });

        Schema::create('fne_certifications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('document_id')->unique();
            $t->string('document_number', 40)->nullable();
            $t->string('document_type', 15);         // invoice, receipt, credit_note, purchase
            $t->string('invoice_type', 10)->default('sale'); // sale | purchase | refund
            $t->uuid('parent_id')->nullable();       // certification de la facture d'origine (avoir)
            $t->string('status', 10)->default('pending'); // pending, certified, failed
            $t->string('environment', 10)->default('test');
            $t->string('template', 3)->nullable();
            $t->string('payment_method', 15)->nullable();
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->timestamp('next_attempt_at')->nullable();
            $t->unsignedSmallInteger('last_status')->nullable();
            $t->text('last_error')->nullable();
            $t->string('fne_invoice_id', 64)->nullable();
            $t->string('reference', 64)->nullable();
            $t->string('ncc', 30)->nullable();
            $t->text('token')->nullable();
            $t->text('verify_url')->nullable();
            $t->text('fiscal_stamp')->nullable();
            $t->json('items')->nullable();           // [{line_id, fne_id, quantity}]
            $t->decimal('amount', 15, 2)->nullable();
            $t->decimal('vat_amount', 15, 2)->nullable();
            $t->string('warning')->nullable();
            $t->integer('sticker_balance')->nullable();
            $t->json('payload')->nullable();
            $t->json('response')->nullable();
            $t->timestamp('certified_at')->nullable();
            $t->foreignId('requested_by')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fne_certifications');
        Schema::dropIfExists('fne_configs');
    }
};
