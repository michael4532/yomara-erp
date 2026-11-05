<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dossiers d'importation : coût de revient réel (fret, assurance, droits, transit)
        Schema::create('ap_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('number', 30)->nullable();
            $t->string('title', 200);
            $t->uuid('partner_id')->nullable()->index();
            $t->string('origin_country', 60)->nullable();
            $t->string('currency', 3)->default('XOF');
            $t->decimal('exchange_rate', 16, 6)->default(1);
            $t->string('incoterm', 10)->nullable();
            $t->string('transport', 10)->default('sea');
            $t->string('status', 12)->default('draft')->index();
            $t->string('allocation', 10)->default('value');
            $t->decimal('vat_rate', 6, 2)->default(18);
            $t->date('order_date')->nullable();
            $t->date('eta')->nullable();
            $t->date('arrived_at')->nullable();
            $t->string('tracking', 120)->nullable();
            $t->string('forwarder', 160)->nullable();
            $t->uuid('purchase_order_id')->nullable();
            $t->string('project_id', 40)->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('applied_at')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });
        Schema::create('ap_import_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('import_id')->index();
            $t->uuid('product_id')->nullable();
            $t->string('description', 255);
            $t->string('hs_code', 20)->nullable();
            $t->decimal('quantity', 14, 3)->default(1);
            $t->decimal('unit_price', 16, 4)->default(0); // en devise du fournisseur
            $t->decimal('weight', 12, 3)->default(0);     // kg par unité
            $t->decimal('duty_rate', 6, 2)->default(0);   // droits et taxes de douane (hors TVA), %
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });
        Schema::create('ap_import_costs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('import_id')->index();
            $t->string('type', 20);
            $t->string('label', 160)->nullable();
            $t->decimal('amount', 16, 2)->default(0);     // en devise de la société
            $t->string('allocation', 10)->nullable();     // value, quantity, weight (sinon celle du dossier)
            $t->string('reference', 80)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });
        // Comparatifs de prix entre fournisseurs
        Schema::create('ap_comparisons', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('number', 30)->nullable();
            $t->string('title', 200);
            $t->string('status', 10)->default('open')->index();
            $t->uuid('request_id')->nullable();
            $t->unsignedBigInteger('spec_id')->nullable();
            $t->date('deadline')->nullable();
            $t->json('lines')->nullable();
            $t->json('offers')->nullable();
            $t->string('awarded', 40)->nullable();
            $t->text('award_note')->nullable();
            $t->uuid('order_id')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });
        // Cahiers des charges
        Schema::create('ap_specs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('number', 30)->nullable();
            $t->string('title', 200);
            $t->string('site', 200)->nullable();
            $t->string('project_id', 40)->nullable();
            $t->text('context')->nullable();
            $t->json('sections')->nullable();
            $t->json('requirements')->nullable();
            $t->date('deadline')->nullable();
            $t->string('status', 10)->default('draft');
            $t->json('sent_to')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });
        // Locations de matériel et services externes
        Schema::create('ap_rentals', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('number', 30)->nullable();
            $t->string('kind', 12)->default('equipment');
            $t->string('title', 200);
            $t->uuid('partner_id')->nullable()->index();
            $t->date('start_date');
            $t->date('end_date');
            $t->date('returned_at')->nullable();
            $t->decimal('rate', 16, 2)->default(0);
            $t->string('rate_unit', 8)->default('day');
            $t->decimal('quantity', 12, 3)->default(1);
            $t->decimal('deposit', 16, 2)->default(0);
            $t->boolean('deposit_returned')->default(false);
            $t->string('site', 200)->nullable();
            $t->string('project_id', 40)->nullable();
            $t->string('reference', 80)->nullable();
            $t->string('status', 10)->default('planned')->index();
            $t->uuid('invoice_id')->nullable();
            $t->unsignedBigInteger('responsible_id')->nullable();
            $t->text('notes')->nullable();
            $t->date('reminded_on')->nullable();
            $t->timestamps();
        });
        Schema::create('ap_rental_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('rental_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type', 12);
            $t->text('body')->nullable();
            $t->json('data')->nullable();
            $t->timestamps();
        });
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->where('code', 'entreprise')->get() as $p) {
                $m = (array) json_decode((string) $p->modules, true);
                if (! in_array('appro', $m, true)) {
                    $m[] = 'appro';
                    DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['ap_rental_events', 'ap_rentals', 'ap_specs', 'ap_comparisons', 'ap_import_costs', 'ap_import_lines', 'ap_imports'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
