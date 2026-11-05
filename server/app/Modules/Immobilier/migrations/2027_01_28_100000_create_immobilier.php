<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('im_properties', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('name', 160);
            $t->string('kind', 20)->default('immeuble'); // immeuble, villa, cour_commune, residence, commerce, bureaux, terrain
            $t->string('address', 255)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('district', 120)->nullable();
            $t->uuid('owner_id')->nullable()->index(); // propriétaire (mandant) ; vide = bien de la société
            $t->decimal('fee_rate', 5, 2)->default(0); // honoraires de gérance (% des loyers encaissés)
            $t->text('notes')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('im_units', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('property_id')->index();
            $t->string('reference', 60);
            $t->string('kind', 20)->default('appartement'); // studio, appartement, villa, chambre, magasin, bureau, entrepot, parking, terrain
            $t->unsignedTinyInteger('rooms')->nullable();
            $t->decimal('surface', 10, 2)->nullable();
            $t->string('floor', 20)->nullable();
            $t->decimal('rent', 14, 2)->default(0);
            $t->decimal('charges', 14, 2)->default(0);
            $t->string('status', 12)->default('vacant'); // vacant, occupied, works
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('im_leases', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('number', 30);
            $t->unsignedBigInteger('unit_id')->index();
            $t->uuid('tenant_id')->index();
            $t->string('usage', 16)->default('habitation'); // habitation, professionnel
            $t->date('start_date');
            $t->date('end_date')->nullable();
            $t->decimal('rent', 14, 2);
            $t->decimal('charges', 14, 2)->default(0);
            $t->decimal('tax_rate', 5, 2)->default(0);
            $t->unsignedTinyInteger('months')->default(1); // périodicité : 1 mensuel, 3 trimestriel, 6, 12
            $t->unsignedTinyInteger('due_day')->default(5);
            $t->decimal('deposit_amount', 14, 2)->default(0);
            $t->decimal('deposit_paid', 14, 2)->default(0);
            $t->date('deposit_paid_at')->nullable();
            $t->decimal('deposit_returned', 14, 2)->default(0);
            $t->decimal('deposit_retained', 14, 2)->default(0);
            $t->string('deposit_note', 300)->nullable();
            $t->unsignedTinyInteger('advance_months')->default(0);
            $t->decimal('revision_rate', 5, 2)->default(0);
            $t->date('next_revision_date')->nullable();
            $t->string('guarantor_name', 160)->nullable();
            $t->string('guarantor_phone', 40)->nullable();
            $t->string('status', 12)->default('active'); // active, ended
            $t->date('ended_at')->nullable();
            $t->string('end_reason', 300)->nullable();
            $t->text('notes')->nullable();
            $t->string('last_error', 255)->nullable();
            $t->timestamps();
        });
        Schema::create('im_rents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('lease_id')->index();
            $t->unsignedBigInteger('unit_id')->index();
            $t->unsignedBigInteger('property_id')->index();
            $t->date('period_start');
            $t->date('period_end');
            $t->decimal('rent', 14, 2);
            $t->decimal('charges', 14, 2)->default(0);
            $t->decimal('total', 14, 2);
            $t->date('due_date');
            $t->uuid('invoice_id')->nullable()->index();
            $t->timestamp('reminded_at')->nullable();
            $t->unsignedSmallInteger('reminders')->default(0);
            $t->timestamps();
            $t->unique(['lease_id', 'period_start']);
        });
        Schema::create('im_expenses', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('property_id')->index();
            $t->unsignedBigInteger('unit_id')->nullable();
            $t->date('date');
            $t->string('label', 200);
            $t->string('category', 20)->default('travaux'); // travaux, entretien, taxe, assurance, syndic, autre
            $t->decimal('amount', 14, 2);
            $t->boolean('owner_charge')->default(true); // déduite du reversement au propriétaire
            $t->string('supplier', 160)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('im_payouts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('owner_id')->index();
            $t->date('period_from');
            $t->date('period_to');
            $t->decimal('collected', 14, 2);
            $t->decimal('fees', 14, 2);
            $t->decimal('expenses', 14, 2);
            $t->decimal('amount', 14, 2);
            $t->date('paid_at');
            $t->string('method', 20)->default('bank_transfer');
            $t->string('reference', 80)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->whereIn('code', ['entreprise', 'sur_mesure'])->get() as $p) {
                $m = (array) json_decode((string) $p->modules, true);
                if (! in_array('immobilier', $m, true)) {
                    $m[] = 'immobilier';
                    DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['im_payouts', 'im_expenses', 'im_rents', 'im_leases', 'im_units', 'im_properties'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
