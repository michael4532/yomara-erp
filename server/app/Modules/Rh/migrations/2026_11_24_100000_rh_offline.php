<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * RH hors ligne : salariés et congés passent en identifiants UUID synchronisables
 * (version, suppression douce, journal de synchronisation), avec le lien vers le compte
 * utilisateur du salarié (espace salarié). Les bulletins de paie suivent le nouvel identifiant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rh_employees_v2', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->foreignId('user_id')->nullable()->index(); // compte de connexion du salarié
            $table->string('matricule', 30);
            $table->string('first_name');
            $table->string('last_name');
            $table->string('gender', 1)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('id_number', 60)->nullable();
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->string('category', 30)->nullable();
            $table->date('hire_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('contract_type', 20)->default('CDI');
            $table->decimal('base_salary', 15, 2)->default(0);
            $table->decimal('transport_allowance', 15, 2)->default(0);
            $table->json('fixed_items')->nullable();
            $table->string('marital_status', 20)->default('single');
            $table->unsignedTinyInteger('children_count')->default(0);
            $table->boolean('spouse_dependent')->default(false);
            $table->decimal('parts_override', 3, 1)->nullable();
            $table->string('cnps_number', 40)->nullable();
            $table->boolean('is_expatriate')->default(false);
            $table->string('payment_method', 20)->default('bank_transfer');
            $table->string('payment_account', 80)->nullable();
            $table->decimal('leave_days_per_month', 4, 2)->default(2.2);
            $table->decimal('leave_opening_balance', 6, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'matricule']);
        });

        Schema::create('rh_leaves_v2', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('employee_id')->index();
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('days', 6, 2);
            $table->string('status', 20)->default('requested'); // requested, approved, rejected, cancelled
            $table->string('reason')->nullable();
            $table->string('decision_note')->nullable();
            $table->foreignId('requested_by')->nullable();
            $table->foreignId('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('paie_payslips_v2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('run_id')->index();
            $table->uuid('employee_id')->index();
            $table->string('number', 40)->nullable();
            $table->json('employee');
            $table->decimal('days_worked', 5, 2)->default(30);
            $table->json('items')->nullable();
            $table->json('result');
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->decimal('cost', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['run_id', 'employee_id']);
        });

        // Copie des données existantes
        $map = [];
        $now = now();
        $changes = [];
        $empCols = Schema::getColumnListing('rh_employees_v2');
        foreach (DB::table('rh_employees')->orderBy('id')->get() as $e) {
            $id = (string) Str::uuid();
            $map[$e->id] = $id;
            $row = array_intersect_key((array) $e, array_flip($empCols));
            DB::table('rh_employees_v2')->insert(['id' => $id, 'version' => 1] + $row);
            $changes[] = ['company_id' => $e->company_id, 'entity' => 'rh_employees', 'record_id' => $id, 'op' => $e->deleted_at ? 'delete' : 'upsert', 'created_at' => $now];
        }
        $leaveCols = Schema::getColumnListing('rh_leaves_v2');
        foreach (DB::table('rh_leaves')->orderBy('id')->get() as $l) {
            if (! isset($map[$l->employee_id])) {
                continue;
            }
            $id = (string) Str::uuid();
            $row = array_intersect_key((array) $l, array_flip($leaveCols));
            DB::table('rh_leaves_v2')->insert(['id' => $id, 'employee_id' => $map[$l->employee_id], 'version' => 1] + $row);
            $changes[] = ['company_id' => $l->company_id, 'entity' => 'rh_leaves', 'record_id' => $id, 'op' => 'upsert', 'created_at' => $now];
        }
        foreach (DB::table('paie_payslips')->orderBy('id')->get() as $p) {
            if (! isset($map[$p->employee_id])) {
                continue;
            }
            DB::table('paie_payslips_v2')->insert(['employee_id' => $map[$p->employee_id]] + array_diff_key((array) $p, ['employee_id' => 1]));
        }
        foreach (array_chunk($changes, 200) as $chunk) {
            DB::table('sync_changes')->insert($chunk);
        }

        Schema::drop('paie_payslips');
        Schema::drop('rh_leaves');
        Schema::drop('rh_employees');
        Schema::rename('rh_employees_v2', 'rh_employees');
        Schema::rename('rh_leaves_v2', 'rh_leaves');
        Schema::rename('paie_payslips_v2', 'paie_payslips');
    }

    public function down(): void
    {
        // Conversion à sens unique (les identifiants d'origine ne sont pas conservés).
    }
};
