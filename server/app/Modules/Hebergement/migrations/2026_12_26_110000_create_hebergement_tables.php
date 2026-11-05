<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hb_services', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->uuid('partner_id')->index();
            $t->string('partner_name')->nullable();
            $t->string('type', 12)->default('domain'); // domain, hosting, ssl, email, maintenance, license, cloud, other
            $t->string('name');
            $t->string('provider', 120)->nullable();
            $t->uuid('supplier_id')->nullable();
            $t->string('plan', 120)->nullable();
            $t->text('details')->nullable();
            $t->decimal('cost_price', 14, 2)->default(0);
            $t->decimal('sale_price', 14, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->unsignedSmallInteger('period_months')->default(12);
            $t->date('start_date')->nullable();
            $t->date('expiry_date');
            $t->boolean('auto_renew')->default(true);
            $t->unsignedSmallInteger('renew_days_before')->default(30);
            $t->boolean('reminders')->default(true);
            $t->string('status', 10)->default('active'); // active, suspended, cancelled
            $t->uuid('product_id')->nullable();
            $t->text('notes')->nullable();
            $t->string('last_reminder', 40)->nullable();
            $t->string('last_error')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'status', 'expiry_date']);
        });

        Schema::create('hb_renewals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('service_id')->index();
            $t->uuid('document_id')->nullable()->index();
            $t->date('period_start');
            $t->date('period_end');
            $t->decimal('amount', 14, 2)->default(0);
            $t->decimal('cost', 14, 2)->default(0);
            $t->string('status', 10)->default('invoiced'); // invoiced, paid, manual, cancelled
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('provider_done_at')->nullable();
            $t->unsignedBigInteger('provider_done_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hb_renewals');
        Schema::dropIfExists('hb_services');
    }
};
