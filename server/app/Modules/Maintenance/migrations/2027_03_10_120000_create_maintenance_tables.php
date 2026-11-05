<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $base = function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable()->index();
        };
        Schema::create('mt_equipment', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('code', 60)->nullable();
            $t->string('category', 80)->nullable();
            $t->string('location', 150)->nullable();
            $t->string('brand', 80)->nullable();
            $t->string('model', 80)->nullable();
            $t->string('serial', 80)->nullable();
            $t->date('commissioned_on')->nullable();
            $t->date('warranty_until')->nullable();
            $t->string('criticality', 8)->default('medium');
            $t->string('status', 12)->default('running')->index();
            $t->string('meter_unit', 8)->nullable();
            $t->decimal('meter_value', 14, 1)->nullable();
            $t->string('supplier', 150)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('mt_plans', function (Blueprint $t) use ($base) {
            $base($t);
            $t->unsignedBigInteger('equipment_id')->index();
            $t->string('title', 200);
            $t->string('trigger', 8)->default('date');
            $t->unsignedInteger('every')->default(1);
            $t->string('unit', 8)->default('months');
            $t->unsignedSmallInteger('lead_days')->default(7);
            $t->date('last_done_on')->nullable();
            $t->decimal('last_meter', 14, 1)->nullable();
            $t->date('next_due_on')->nullable()->index();
            $t->decimal('next_meter', 14, 1)->nullable();
            $t->unsignedBigInteger('assignee_id')->nullable();
            $t->unsignedSmallInteger('estimated_minutes')->nullable();
            $t->text('checklist')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });
        Schema::create('mt_orders', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('number', 30)->nullable()->index();
            $t->string('title', 200);
            $t->unsignedBigInteger('equipment_id')->nullable()->index();
            $t->unsignedBigInteger('plan_id')->nullable()->index();
            $t->string('kind', 12)->default('corrective');
            $t->string('priority', 8)->default('normal');
            $t->string('status', 12)->default('requested')->index();
            $t->boolean('equipment_stopped')->default(false);
            $t->unsignedBigInteger('requester_id')->nullable();
            $t->unsignedBigInteger('assignee_id')->nullable()->index();
            $t->date('planned_on')->nullable()->index();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('finished_at')->nullable();
            $t->decimal('downtime_hours', 8, 2)->nullable();
            $t->decimal('labour_hours', 8, 2)->nullable();
            $t->decimal('labour_cost', 14, 2)->nullable();
            $t->decimal('parts_cost', 14, 2)->nullable();
            $t->decimal('external_cost', 14, 2)->nullable();
            $t->string('parts_used', 500)->nullable();
            $t->string('cause', 12)->nullable();
            $t->decimal('meter_reading', 14, 1)->nullable();
            $t->text('description')->nullable();
            $t->text('checklist')->nullable();
            $t->text('report')->nullable();
            $t->dateTime('reminded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('mt_readings', function (Blueprint $t) use ($base) {
            $base($t);
            $t->unsignedBigInteger('equipment_id')->index();
            $t->decimal('value', 14, 1);
            $t->date('read_on');
            $t->string('notes', 255)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mt_readings', 'mt_orders', 'mt_plans', 'mt_equipment'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
