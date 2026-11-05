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
        Schema::create('jr_contracts', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('reference', 60)->nullable();
            $t->string('kind', 15)->default('service');
            $t->string('status', 12)->default('draft')->index();
            $t->string('partner_id', 40)->nullable()->index();
            $t->string('employee_id', 40)->nullable()->index();
            $t->string('counterparty', 200)->nullable();
            $t->date('signed_on')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable()->index();
            $t->boolean('tacit_renewal')->default(false);
            $t->unsignedSmallInteger('notice_days')->nullable();
            $t->decimal('amount', 16, 2)->nullable();
            $t->string('periodicity', 12)->nullable();
            $t->unsignedBigInteger('template_id')->nullable();
            $t->longText('body')->nullable();
            $t->string('file_url', 500)->nullable();
            $t->text('notes')->nullable();
            $t->date('alerted_on')->nullable();
            $t->timestamps();
        });
        Schema::create('jr_templates', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('kind', 15)->default('service');
            $t->longText('body')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('jr_cases', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('reference', 60)->nullable();
            $t->string('kind', 15)->default('commercial');
            $t->string('role', 10)->default('plaintiff');
            $t->string('status', 12)->default('open')->index();
            $t->string('partner_id', 40)->nullable();
            $t->string('employee_id', 40)->nullable();
            $t->string('opposing_party', 200)->nullable();
            $t->string('court', 200)->nullable();
            $t->string('lawyer', 200)->nullable();
            $t->decimal('amount_at_stake', 16, 2)->nullable();
            $t->decimal('costs', 16, 2)->nullable();
            $t->string('risk', 10)->default('medium');
            $t->date('opened_on')->nullable();
            $t->text('summary')->nullable();
            $t->text('outcome')->nullable();
            $t->timestamps();
        });
        Schema::create('jr_deadlines', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('kind', 15)->default('hearing');
            $t->dateTime('due_at')->index();
            $t->unsignedBigInteger('case_id')->nullable()->index();
            $t->unsignedBigInteger('contract_id')->nullable()->index();
            $t->boolean('done')->default(false);
            $t->string('place', 200)->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('reminded_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['jr_deadlines', 'jr_cases', 'jr_templates', 'jr_contracts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
