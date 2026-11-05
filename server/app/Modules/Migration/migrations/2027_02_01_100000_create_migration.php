<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mig_jobs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('source', 20); // dolibarr, odoo, erpnext, file
            $t->string('vendor', 30)->nullable(); // éditeur du fichier (sage, quickbooks…)
            $t->string('label', 120)->nullable();
            $t->text('secret')->nullable(); // connexion chiffrée, effacée après la migration
            $t->json('files')->nullable();
            $t->json('options')->nullable();
            $t->string('status', 12)->default('ready'); // ready, queued, running, done, failed
            $t->json('progress')->nullable();
            $t->json('counts')->nullable();
            $t->json('warnings')->nullable();
            $t->string('error', 500)->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
        Schema::create('mig_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id');
            $t->string('source', 64);
            $t->string('entity', 20);
            $t->string('external_id', 100);
            $t->string('local_id', 64);
            $t->timestamps();
            $t->unique(['company_id', 'source', 'entity', 'external_id'], 'mig_links_unique');
        });
        Schema::table('trade_documents', fn (Blueprint $t) => $t->string('migrated_from', 40)->nullable());
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->get() as $p) {
                $m = (array) json_decode((string) $p->modules, true);
                if ($m && ! in_array('migration', $m, true)) {
                    $m[] = 'migration';
                    DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mig_links');
        Schema::dropIfExists('mig_jobs');
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn('migrated_from'));
    }
};
