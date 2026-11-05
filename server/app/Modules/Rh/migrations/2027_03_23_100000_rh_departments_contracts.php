<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Départements (avec chefs et droits de regard sur l'équipe), contrats enregistrés par la RH,
 * statut du personnel (salarié, stagiaire…), déclaration CNPS, CMU, assurance, horaires de travail.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sync = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };

        Schema::create('rh_departments', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 120);
            $t->string('code', 30)->nullable();
            $t->uuid('parent_id')->nullable();
            $t->json('head_ids')->nullable(); // chefs de département (fiches salariés)
            $t->json('visibility')->nullable(); // ce que les chefs voient de leur équipe : contract, salary, documents, personal, presence
            $t->string('color', 9)->nullable();
            $t->text('description')->nullable();
            $t->boolean('active')->default(true);
            $sync($t);
        });

        Schema::create('rh_contracts', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('employee_id')->index();
            $t->string('number', 30)->nullable();
            $t->string('type', 15); // CDI, CDD, stage, apprentissage, prestation, journalier
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->date('trial_end_date')->nullable();
            $t->date('signed_on')->nullable();
            $t->decimal('salary', 15, 2)->default(0);
            $t->decimal('weekly_hours', 5, 2)->nullable();
            $t->uuid('position_id')->nullable();
            $t->string('status', 12)->default('active'); // draft, active, ended, terminated
            $t->string('end_reason', 200)->nullable();
            $t->string('file_url', 500)->nullable();
            $t->text('notes')->nullable();
            $sync($t);
        });

        Schema::table('rh_employees', function (Blueprint $t) {
            $t->uuid('department_id')->nullable()->index();
            $t->string('staff_type', 15)->default('employee'); // employee, intern, apprentice, contractor, daily
            $t->boolean('cnps_declared')->default(true);
            $t->boolean('cmu_enrolled')->default(true);
            $t->boolean('has_insurance')->default(false);
            $t->string('insurance_name', 120)->nullable();
            $t->decimal('insurance_employee', 15, 2)->default(0); // part salarié mensuelle
            $t->decimal('insurance_employer', 15, 2)->default(0); // part employeur mensuelle
            $t->decimal('weekly_hours', 5, 2)->default(40);
            $t->json('work_schedule')->nullable(); // {1: {start: "08:00", end: "17:00", break: 60}, …}
        });

        Schema::table('rh_requests', function (Blueprint $t) {
            $t->json('cc_user_ids')->nullable(); // personnes choisies en copie (équipe RH, direction)
            $t->json('attachments')->nullable(); // justificatifs : [{id, name, mime, size, at, by}]
        });

        // Départements créés à partir des services déjà saisis sur les fiches
        foreach (DB::table('rh_employees')->whereNotNull('department')->where('department', '!=', '')->select('company_id', 'department')->distinct()->get() as $row) {
            $name = trim($row->department);
            $id = DB::table('rh_departments')->where('company_id', $row->company_id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->value('id');
            if (! $id) {
                $id = (string) Str::uuid();
                DB::table('rh_departments')->insert(['id' => $id, 'company_id' => $row->company_id, 'name' => $name, 'head_ids' => '[]', 'visibility' => json_encode(['presence' => true]), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('rh_employees')->where('company_id', $row->company_id)->where('department', $row->department)->update(['department_id' => $id]);
        }
        // Stagiaires et journaliers existants : statut et non déclarés
        DB::table('rh_employees')->where('contract_type', 'stage')->update(['staff_type' => 'intern', 'cnps_declared' => false, 'cmu_enrolled' => false]);
        DB::table('rh_employees')->where('contract_type', 'consultant')->update(['staff_type' => 'contractor', 'cnps_declared' => false, 'cmu_enrolled' => false]);
        DB::table('rh_employees')->where('contract_type', 'journalier')->update(['staff_type' => 'daily']);
    }

    public function down(): void
    {
        Schema::table('rh_requests', fn (Blueprint $t) => $t->dropColumn(['cc_user_ids', 'attachments']));
        Schema::table('rh_employees', fn (Blueprint $t) => $t->dropColumn(['department_id', 'staff_type', 'cnps_declared', 'cmu_enrolled', 'has_insurance', 'insurance_name', 'insurance_employee', 'insurance_employer', 'weekly_hours', 'work_schedule']));
        Schema::dropIfExists('rh_contracts');
        Schema::dropIfExists('rh_departments');
    }
};
