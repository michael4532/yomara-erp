<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $base = function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable()->index();
        };
        Schema::create('mp_tenders', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 250);
            $t->string('reference', 80)->nullable();
            $t->string('buyer', 200)->nullable();
            $t->string('partner_id', 40)->nullable()->index();
            $t->string('procedure', 15)->default('open');
            $t->string('sector', 15)->default('supplies');
            $t->string('status', 12)->default('watch')->index();
            $t->string('lot', 120)->nullable();
            $t->date('published_on')->nullable();
            $t->date('questions_deadline')->nullable();
            $t->dateTime('submission_at')->nullable()->index();
            $t->dateTime('opening_at')->nullable();
            $t->decimal('estimated_amount', 16, 2)->nullable();
            $t->decimal('our_bid', 16, 2)->nullable();
            $t->decimal('bid_bond_amount', 16, 2)->nullable();
            $t->decimal('dao_cost', 16, 2)->nullable();
            $t->unsignedSmallInteger('validity_days')->nullable();
            $t->string('chance', 8)->nullable();
            $t->string('source_url', 500)->nullable();
            $t->string('winner', 200)->nullable();
            $t->decimal('winning_amount', 16, 2)->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('reminded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('mp_pieces', function (Blueprint $t) use ($base) {
            $base($t);
            $t->unsignedBigInteger('tender_id')->index();
            $t->string('title', 200);
            $t->string('category', 12)->default('admin');
            $t->string('status', 12)->default('todo');
            $t->date('due_date')->nullable();
            $t->unsignedBigInteger('document_id')->nullable();
            $t->string('file_url', 500)->nullable();
            $t->unsignedSmallInteger('position')->default(0);
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('mp_documents', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('kind', 15)->default('other');
            $t->string('number', 80)->nullable();
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable()->index();
            $t->string('file_url', 500)->nullable();
            $t->text('notes')->nullable();
            $t->date('alerted_on')->nullable();
            $t->timestamps();
        });
        Schema::create('mp_contracts', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 250);
            $t->string('number', 80)->nullable();
            $t->unsignedBigInteger('tender_id')->nullable()->index();
            $t->string('buyer', 200)->nullable();
            $t->string('partner_id', 40)->nullable()->index();
            $t->string('status', 15)->default('signed')->index();
            $t->decimal('amount', 16, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->date('signed_on')->nullable();
            $t->date('start_order_on')->nullable();
            $t->unsignedInteger('duration_days')->nullable();
            $t->date('planned_end')->nullable();
            $t->decimal('advance_percent', 5, 2)->default(0);
            $t->decimal('retention_percent', 5, 2)->default(5);
            $t->decimal('penalty_per_day', 8, 3)->nullable();
            $t->date('provisional_acceptance_on')->nullable();
            $t->date('final_acceptance_on')->nullable();
            $t->unsignedSmallInteger('warranty_months')->default(12);
            $t->text('notes')->nullable();
            $t->date('alerted_on')->nullable();
            $t->timestamps();
        });
        Schema::create('mp_statements', function (Blueprint $t) use ($base) {
            $base($t);
            $t->unsignedBigInteger('contract_id')->index();
            $t->string('number', 40)->nullable();
            $t->date('period_end')->nullable();
            $t->decimal('progress_percent', 5, 2)->nullable();
            $t->decimal('amount', 16, 2)->default(0);
            $t->decimal('retention', 16, 2)->default(0);
            $t->decimal('advance_repayment', 16, 2)->default(0);
            $t->decimal('net', 16, 2)->default(0);
            $t->string('status', 12)->default('draft')->index();
            $t->date('submitted_on')->nullable();
            $t->date('paid_on')->nullable();
            $t->string('invoice_id', 40)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('mp_bonds', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('kind', 12)->default('bid');
            $t->unsignedBigInteger('tender_id')->nullable()->index();
            $t->unsignedBigInteger('contract_id')->nullable()->index();
            $t->string('bank', 150)->nullable();
            $t->string('reference', 80)->nullable();
            $t->decimal('amount', 16, 2)->default(0);
            $t->decimal('fees', 16, 2)->nullable();
            $t->date('issued_on')->nullable();
            $t->date('expires_on')->nullable()->index();
            $t->string('status', 12)->default('requested')->index();
            $t->date('released_on')->nullable();
            $t->text('notes')->nullable();
            $t->date('alerted_on')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mp_bonds', 'mp_statements', 'mp_contracts', 'mp_documents', 'mp_pieces', 'mp_tenders'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
