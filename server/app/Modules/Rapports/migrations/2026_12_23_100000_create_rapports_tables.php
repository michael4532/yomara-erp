<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sync = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };

        Schema::create('rp_reports', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->string('source', 40);
            $t->json('config');
            $t->string('folder', 80)->nullable();
            $t->string('visibility', 10)->default('private'); // private | company | roles
            $t->json('role_ids')->nullable();
            $t->foreignId('owner_id')->nullable()->index();
            $t->string('template', 40)->nullable();
            $sync($t);
        });

        Schema::create('rp_dashboards', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 150);
            $t->text('description')->nullable();
            $t->json('widgets')->nullable();
            $t->string('period', 20)->nullable(); // période imposée à tous les rapports (null : celle de chaque rapport)
            $t->string('visibility', 10)->default('private');
            $t->json('role_ids')->nullable();
            $t->foreignId('owner_id')->nullable()->index();
            $t->unsignedSmallInteger('sort')->default(0);
            $sync($t);
        });

        Schema::create('rp_schedules', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('report_id')->nullable()->index();
            $t->uuid('dashboard_id')->nullable()->index();
            $t->foreignId('owner_id');
            $t->string('frequency', 10); // daily | weekly | monthly
            $t->unsignedTinyInteger('weekday')->default(1); // 1 = lundi
            $t->unsignedTinyInteger('monthday')->default(1);
            $t->unsignedTinyInteger('hour')->default(7);
            $t->json('recipients');
            $t->string('format', 5)->default('pdf'); // pdf | xlsx
            $t->string('period', 20)->nullable();
            $t->text('message')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamp('next_run_at')->nullable()->index();
            $t->timestamp('last_sent_at')->nullable();
            $t->string('last_status', 10)->nullable();
            $t->string('last_error')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rp_schedules');
        Schema::dropIfExists('rp_dashboards');
        Schema::dropIfExists('rp_reports');
    }
};
