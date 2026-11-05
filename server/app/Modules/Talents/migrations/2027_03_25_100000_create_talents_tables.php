<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Entretiens d'évaluation, plan de formation, parcours d'arrivée et de départ. */
return new class extends Migration
{
    public function up(): void
    {
        $sync = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };
        Schema::create('tal_reviews', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('employee_id')->index();
            $t->uuid('reviewer_id')->nullable()->index(); // responsable qui mène l'entretien (fiche salarié)
            $t->string('type', 12)->default('annual'); // annual, trial, mid, exit
            $t->string('period', 40)->nullable(); // « 2026 », « S1 2027 »…
            $t->date('scheduled_on')->nullable();
            $t->string('status', 12)->default('planned'); // planned, self, manager, done, cancelled
            $t->json('objectives')->nullable(); // [{title, weight, result, score}]
            $t->json('ratings')->nullable(); // [{criterion, self, score}]
            $t->decimal('score', 4, 2)->nullable(); // moyenne sur 5
            $t->string('outcome', 20)->nullable(); // trial : confirm, extend, end ; annual : promotion, raise, training, none
            $t->text('strengths')->nullable();
            $t->text('improvements')->nullable();
            $t->text('employee_comment')->nullable();
            $t->text('manager_comment')->nullable();
            $t->json('next_objectives')->nullable(); // [{title, due}]
            $t->text('training_needs')->nullable();
            $t->timestamp('completed_at')->nullable();
            $sync($t);
        });
        Schema::create('tal_trainings', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('title', 160);
            $t->string('category', 80)->nullable();
            $t->string('provider', 160)->nullable();
            $t->string('location', 160)->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->decimal('hours', 7, 2)->nullable();
            $t->decimal('cost', 15, 2)->nullable();
            $t->boolean('funded')->default(false); // prise en charge (FDFP, OPCO…)
            $t->decimal('funded_amount', 15, 2)->nullable();
            $t->string('status', 12)->default('planned'); // planned, ongoing, done, cancelled
            $t->json('participants')->nullable(); // [{employee_id, attended, rating, certificate}]
            $t->text('description')->nullable();
            $sync($t);
        });
        Schema::create('tal_checklists', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('employee_id')->index();
            $t->string('kind', 12)->default('onboarding'); // onboarding, offboarding
            $t->string('title', 160)->nullable();
            $t->date('start_date')->nullable();
            $t->date('due_date')->nullable();
            $t->json('items'); // [{id, label, owner: rh|manager|employee|it, done, done_at, done_by}]
            $t->string('status', 12)->default('open'); // open, done
            $t->text('notes')->nullable();
            $sync($t);
        });
        // Rôle RH existant : accès au module
        if (Schema::hasTable('roles')) {
            foreach (DB::table('roles')->where('name', 'RH / Paie')->get() as $r) {
                $perms = (array) json_decode((string) $r->permissions, true);
                if (in_array('rh.*', $perms, true) && ! in_array('talents.*', $perms, true)) {
                    $perms[] = 'talents.*';
                    DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode(array_values($perms))]);
                }
            }
        }
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->whereIn('code', ['entreprise', 'sur_mesure'])->get() as $p) {
                $m = (array) json_decode((string) $p->modules, true);
                if (! in_array('talents', $m, true)) {
                    $m[] = 'talents';
                    DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tal_checklists');
        Schema::dropIfExists('tal_trainings');
        Schema::dropIfExists('tal_reviews');
    }
};
