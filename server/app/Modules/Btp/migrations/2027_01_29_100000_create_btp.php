<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('btp_sites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('project_id')->index();
            $t->uuid('client_id')->nullable()->index();
            $t->string('name', 160);
            $t->string('reference', 40)->nullable(); // n° de marché / bon de commande du client
            $t->string('address', 255)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('status', 12)->default('study'); // study, won, in_progress, received, closed, lost
            $t->decimal('contract_amount', 16, 2)->default(0); // montant du marché HT
            $t->decimal('tax_rate', 5, 2)->default(18);
            $t->decimal('retention_rate', 5, 2)->default(5); // retenue de garantie (% du TTC)
            $t->decimal('advance_rate', 5, 2)->default(0); // avance de démarrage (% du marché)
            $t->uuid('quote_id')->nullable();
            $t->uuid('advance_invoice_id')->nullable();
            $t->uuid('release_invoice_id')->nullable();
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->date('reception_date')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('btp_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id')->index();
            $t->unsignedInteger('position')->default(0);
            $t->string('lot', 120); // Gros œuvre, Plomberie, Électricité…
            $t->string('code', 20)->nullable();
            $t->string('designation', 255);
            $t->string('unit', 12)->default('u');
            $t->decimal('quantity', 14, 3)->default(0);
            $t->decimal('unit_cost', 14, 2)->default(0); // déboursé sec unitaire
            $t->decimal('unit_price', 14, 2)->default(0); // prix de vente unitaire HT
            $t->timestamps();
        });
        Schema::create('btp_situations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id')->index();
            $t->unsignedInteger('number');
            $t->date('date');
            $t->json('progress'); // {item_id: quantité cumulée exécutée}
            $t->decimal('works_cumul', 16, 2);
            $t->decimal('works_previous', 16, 2);
            $t->decimal('works_amount', 16, 2);
            $t->decimal('advance_repaid', 16, 2)->default(0);
            $t->decimal('retention', 16, 2)->default(0);
            $t->decimal('tax', 16, 2)->default(0);
            $t->decimal('net', 16, 2);
            $t->uuid('invoice_id')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['site_id', 'number']);
        });
        Schema::create('btp_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id')->index();
            $t->date('date');
            $t->string('weather', 20)->nullable();
            $t->unsignedSmallInteger('workers')->default(0);
            $t->text('work_done')->nullable();
            $t->text('incidents')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
        });
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->whereIn('code', ['entreprise', 'sur_mesure'])->get() as $p) {
                $m = (array) json_decode((string) $p->modules, true);
                if (! in_array('btp', $m, true)) {
                    $m[] = 'btp';
                    DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['btp_logs', 'btp_situations', 'btp_items', 'btp_sites'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
