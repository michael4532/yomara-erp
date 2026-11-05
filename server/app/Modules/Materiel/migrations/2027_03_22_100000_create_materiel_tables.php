<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Matériel de l'entreprise et ses sorties (intervention, tournage, chantier) avec retour et bon signé. */
return new class extends Migration
{
    public function up(): void
    {
        $sync = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };
        Schema::create('mat_items', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 160);
            $t->string('code', 40)->nullable();
            $t->string('category', 80)->nullable();
            $t->string('brand', 80)->nullable();
            $t->string('model', 80)->nullable();
            $t->string('serial', 80)->nullable();
            $t->string('department', 120)->nullable(); // service propriétaire
            $t->string('location', 120)->nullable();
            $t->decimal('value', 15, 2)->nullable();
            $t->string('status', 12)->default('available'); // available, out, repair, lost, retired
            $t->text('notes')->nullable();
            $sync($t);
        });
        Schema::create('mat_checkouts', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 30)->nullable();
            $t->uuid('employee_id')->nullable()->index(); // porteur (fiche salarié)
            $t->string('holder_name', 150)->nullable(); // porteur sans fiche salarié
            $t->foreignId('holder_user_id')->nullable();
            $t->string('department', 120)->nullable();
            $t->string('purpose', 200);
            $t->string('destination', 200)->nullable();
            $t->string('partner_id', 40)->nullable(); // client de l'intervention
            $t->timestamp('out_at')->nullable();
            $t->timestamp('expected_back_at')->nullable();
            $t->timestamp('back_at')->nullable();
            $t->string('status', 12)->default('out'); // out, partial, returned, cancelled
            $t->json('lines'); // [{item_id, name, serial, condition_out, returned, returned_at, condition_in, note}]
            $t->json('signers')->nullable(); // [{name, label, employee_id, user_id, email}]
            $t->uuid('signature_id')->nullable();
            $t->string('signature_status', 15)->nullable(); // sent, signed, declined, cancelled
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable();
            $sync($t);
        });
        // Formules qui comprennent tous les modules
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->whereIn('code', ['entreprise', 'sur_mesure'])->get() as $p) {
                $m = (array) json_decode((string) $p->modules, true);
                if (! in_array('materiel', $m, true)) {
                    $m[] = 'materiel';
                    DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mat_checkouts');
        Schema::dropIfExists('mat_items');
    }
};
