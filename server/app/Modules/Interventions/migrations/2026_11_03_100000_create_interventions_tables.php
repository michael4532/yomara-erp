<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('it_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('number', 40)->nullable();
            $table->uuid('partner_id')->index();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->string('frequency', 20)->default('quarterly'); // none, monthly, quarterly, semiannual, annual
            $table->date('next_visit')->nullable();
            $table->unsignedSmallInteger('visit_minutes')->default(120);
            $table->unsignedSmallInteger('response_hours')->nullable(); // délai d'intervention garanti
            $table->decimal('price', 15, 2)->default(0);
            $table->string('billing', 20)->default('monthly'); // monthly, quarterly, annual, none
            $table->boolean('labor_included')->default(true);
            $table->boolean('parts_included')->default(false);
            $table->foreignId('technician_id')->nullable();
            $table->string('status', 20)->default('active'); // active, suspended, ended
            $table->date('invoiced_until')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('it_equipments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('partner_id')->nullable()->index();
            $table->uuid('contract_id')->nullable()->index();
            $table->uuid('product_id')->nullable();
            $table->string('name');
            $table->string('category', 60)->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->string('serial_number', 120)->nullable();
            $table->string('location')->nullable(); // site, étage, pièce
            $table->date('installed_at')->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('status', 20)->default('active'); // active, broken, retired
            $table->text('notes')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('it_interventions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('number', 40)->nullable();
            $table->uuid('partner_id')->nullable()->index();
            $table->uuid('contract_id')->nullable()->index();
            $table->uuid('equipment_id')->nullable()->index();
            $table->string('type', 20)->default('corrective'); // corrective, preventive, installation, other
            $table->unsignedTinyInteger('priority')->default(1); // 0 basse, 1 normale, 2 haute, 3 urgente
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('city', 120)->nullable();
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(60);
            $table->foreignId('technician_id')->nullable()->index();
            $table->string('status', 20)->default('planned'); // requested, planned, en_route, in_progress, done, cancelled
            $table->string('source', 20)->default('manual'); // manual, portal, contract
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('worked_minutes')->nullable();
            $table->text('report')->nullable();
            $table->text('recommendations')->nullable();
            $table->json('checklist')->nullable();
            $table->json('parts')->nullable();
            $table->json('photos')->nullable();
            $table->longText('signature')->nullable();
            $table->string('signed_by', 120)->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->unsignedTinyInteger('satisfaction')->nullable();
            $table->boolean('billable')->default(true);
            $table->decimal('labor_rate', 15, 2)->default(0);
            $table->decimal('travel_fee', 15, 2)->default(0);
            $table->uuid('invoice_id')->nullable();
            $table->boolean('stock_posted')->default(false);
            $table->string('report_token', 64)->nullable()->unique();
            $table->boolean('notify')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('it_interventions');
        Schema::dropIfExists('it_equipments');
        Schema::dropIfExists('it_contracts');
    }
};
