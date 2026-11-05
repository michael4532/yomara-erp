<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rh_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('matricule', 30);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 1)->nullable(); // M / F
            $table->date('birth_date')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('id_number', 60)->nullable(); // CNI / passeport
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('category', 30)->nullable(); // catégorie convention collective
            $table->date('hire_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('contract_type', 20)->default('CDI'); // CDI, CDD, stage, journalier
            $table->decimal('base_salary', 15, 2)->default(0);
            $table->decimal('transport_allowance', 15, 2)->default(0);
            $table->json('fixed_items')->nullable(); // primes fixes mensuelles
            $table->string('marital_status', 20)->default('single'); // single, married, divorced, widowed
            $table->unsignedTinyInteger('children_count')->default(0);
            $table->boolean('spouse_dependent')->default(false); // conjoint sans emploi (CMU)
            $table->decimal('parts_override', 3, 1)->nullable();
            $table->string('cnps_number', 40)->nullable();
            $table->boolean('is_expatriate')->default(false);
            $table->string('payment_method', 20)->default('bank_transfer'); // bank_transfer, cash, mobile_money
            $table->string('payment_account', 80)->nullable();
            $table->decimal('leave_days_per_month', 4, 2)->default(2.2); // 2,2 jours ouvrables / mois (Code du travail CI)
            $table->decimal('leave_opening_balance', 6, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'matricule']);
        });

        Schema::create('rh_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('employee_id')->index();
            $table->string('type', 20); // annual, sick, maternity, unpaid, exceptional, other
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 6, 2);
            $table->string('status', 20)->default('requested'); // requested, approved, rejected, cancelled
            $table->string('reason')->nullable();
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rh_leaves');
        Schema::dropIfExists('rh_employees');
    }
};
