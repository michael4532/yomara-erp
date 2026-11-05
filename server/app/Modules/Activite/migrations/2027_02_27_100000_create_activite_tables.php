<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ra_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->date('date')->index();
            $t->string('title', 255);
            $t->text('details')->nullable();
            $t->unsignedInteger('minutes')->nullable();
            $t->string('source_module', 40)->nullable();
            $t->string('source_type', 40)->nullable();
            $t->string('source_id', 40)->nullable();
            $t->timestamps();
        });
        Schema::create('ra_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->date('period_start');
            $t->date('period_end');
            $t->string('title', 255);
            $t->string('status', 10)->default('draft')->index();
            $t->text('summary')->nullable();
            $t->text('difficulties')->nullable();
            $t->text('next_steps')->nullable();
            $t->json('excluded')->nullable();
            $t->json('items')->nullable();
            $t->unsignedBigInteger('supervisor_id')->nullable()->index();
            $t->text('message')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->text('review_comment')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'period_start', 'period_end']);
        });
        if (! Schema::hasColumn('users', 'supervisor_id')) {
            Schema::table('users', fn (Blueprint $t) => $t->unsignedBigInteger('supervisor_id')->nullable());
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ra_reports');
        Schema::dropIfExists('ra_entries');
        if (Schema::hasColumn('users', 'supervisor_id')) {
            Schema::table('users', fn (Blueprint $t) => $t->dropColumn('supervisor_id'));
        }
    }
};
