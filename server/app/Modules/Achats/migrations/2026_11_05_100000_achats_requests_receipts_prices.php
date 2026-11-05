<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_documents', function (Blueprint $table) {
            $table->foreignId('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('approval_requested_at')->nullable();
            $table->string('receipt_status', 20)->nullable(); // none, partial, received (bons de commande)
        });

        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('number', 40)->nullable();
            $table->foreignId('requester_id')->nullable()->index();
            $table->string('department', 120)->nullable();
            $table->string('title');
            $table->text('reason')->nullable();
            $table->date('needed_by')->nullable();
            $table->unsignedTinyInteger('priority')->default(1);
            $table->json('lines')->nullable(); // product_id, description, quantity, unit, estimated_price, supplier_id
            $table->decimal('estimated_total', 15, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft, submitted, approved, rejected, ordered, cancelled
            $table->foreignId('approver_id')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->json('order_ids')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('number', 40)->nullable();
            $table->uuid('order_id')->index();
            $table->uuid('partner_id')->nullable();
            $table->uuid('warehouse_id')->nullable();
            $table->date('date');
            $table->string('supplier_reference', 80)->nullable(); // n° du bon de livraison fournisseur
            $table->json('lines'); // order_line_id, product_id, description, quantity
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('done'); // done, cancelled
            $table->foreignId('user_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('supplier_prices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('partner_id')->index();
            $table->uuid('product_id')->index();
            $table->decimal('price', 15, 2)->default(0);
            $table->decimal('min_qty', 12, 3)->default(1);
            $table->unsignedSmallInteger('lead_days')->nullable();
            $table->string('supplier_ref', 80)->nullable();
            $table->boolean('preferred')->default(false);
            $table->date('last_purchase_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_prices');
        Schema::dropIfExists('purchase_receipts');
        Schema::dropIfExists('purchase_requests');
        Schema::table('trade_documents', function (Blueprint $table) {
            $table->dropColumn(['approved_by', 'approved_at', 'approval_requested_at', 'receipt_status']);
        });
    }
};
