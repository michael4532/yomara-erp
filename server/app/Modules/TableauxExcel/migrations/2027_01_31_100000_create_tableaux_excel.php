<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('xl_workbooks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('name', 120);
            $t->string('template', 30);
            $t->string('theme', 20)->default('ocean');
            $t->unsignedSmallInteger('year')->nullable(); // null = année en cours
            $t->uuid('branch_id')->nullable();
            $t->boolean('protect')->default(false);
            $t->string('frequency', 10)->default('none'); // none, weekly, monthly
            $t->json('recipients')->nullable();
            $t->text('message')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->timestamp('last_generated_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->string('last_status', 10)->nullable();
            $t->string('last_error', 255)->nullable();
            $t->unsignedInteger('downloads')->default(0);
            $t->timestamps();
        });
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->whereIn('code', ['professional', 'entreprise', 'sur_mesure'])->get() as $p) {
                $m = (array) json_decode((string) $p->modules, true);
                if (! in_array('tableaux_excel', $m, true)) {
                    $m[] = 'tableaux_excel';
                    DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('xl_workbooks');
    }
};
