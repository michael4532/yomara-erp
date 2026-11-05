<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Yomeet : minutes achetées par les sociétés (forfaits mensuels) et réunion liée aux événements de l'agenda. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('vis_credits')) {
            Schema::create('vis_credits', function (Blueprint $t) {
                $t->id();
                $t->foreignId('company_id')->index();
                $t->unsignedInteger('minutes');
                $t->timestamp('starts_at');
                $t->timestamp('ends_at');
                $t->string('source', 20)->default('purchase'); // purchase, gift
                $t->foreignId('order_id')->nullable();
                $t->string('label', 120)->nullable();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('ag_events') && ! Schema::hasColumn('ag_events', 'yomeet')) {
            Schema::table('ag_events', fn (Blueprint $t) => $t->json('yomeet')->nullable());
        }
        if (Schema::hasTable('modules')) {
            DB::table('modules')->where('key', 'visio')->where('name', 'Réunions & webinaires')->update(['name' => 'Yomeet : réunions & webinaires']);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vis_credits');
        if (Schema::hasColumn('ag_events', 'yomeet')) {
            Schema::table('ag_events', fn (Blueprint $t) => $t->dropColumn('yomeet'));
        }
    }
};
