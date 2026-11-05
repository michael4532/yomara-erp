<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sync = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };

        Schema::create('nf_categories', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->string('gl_code', 12); // compte de charge
            $t->decimal('max_amount', 15, 2)->nullable(); // plafond par dépense
            $t->boolean('receipt_required')->default(true);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $sync($t);
        });

        Schema::create('nf_expenses', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('employee_id')->index();
            $t->uuid('category_id')->nullable();
            $t->date('date');
            $t->decimal('amount', 15, 2);
            $t->string('description');
            $t->string('merchant')->nullable(); // fournisseur, station, restaurant…
            $t->string('project')->nullable(); // mission, client, chantier
            $t->string('status', 12)->default('draft'); // draft, submitted, approved, rejected, paid
            $t->string('receipt_path')->nullable();
            $t->string('receipt_name')->nullable();
            $t->string('decision_note')->nullable();
            $t->foreignId('submitted_by')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignId('decided_by')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('paid_via', 12)->nullable(); // treasury | payroll | cash
            $t->string('payment_ref')->nullable(); // opération de trésorerie ou bulletin
            $t->date('paid_at')->nullable();
            $sync($t);
            $t->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nf_expenses');
        Schema::dropIfExists('nf_categories');
    }
};
