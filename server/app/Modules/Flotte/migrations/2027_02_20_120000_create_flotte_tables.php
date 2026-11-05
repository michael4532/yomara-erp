<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fl_vehicles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('title', 120);
            $t->string('plate', 30)->nullable();
            $t->string('kind', 15)->default('car');
            $t->string('brand', 60)->nullable();
            $t->string('model', 60)->nullable();
            $t->string('fuel', 10)->default('diesel');
            $t->unsignedSmallInteger('seats')->nullable();
            $t->string('status', 12)->default('available')->index();
            $t->unsignedInteger('odometer')->default(0);
            $t->unsignedBigInteger('driver_id')->nullable();
            $t->date('insurance_until')->nullable();
            $t->date('inspection_until')->nullable();
            $t->unsignedInteger('next_service_km')->nullable();
            $t->date('next_service_date')->nullable();
            $t->text('notes')->nullable();
            $t->date('alerted_on')->nullable();
            $t->timestamps();
        });
        Schema::create('fl_trips', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('title', 200);
            $t->string('number', 30)->nullable();
            $t->unsignedBigInteger('vehicle_id')->nullable()->index();
            $t->unsignedBigInteger('requester_id')->nullable()->index();
            $t->unsignedBigInteger('driver_id')->nullable()->index();
            $t->string('passengers', 255)->nullable();
            $t->string('purpose', 15)->default('customer');
            $t->string('partner_id', 40)->nullable();
            $t->string('destination', 255)->nullable();
            $t->dateTime('planned_start')->nullable()->index();
            $t->dateTime('planned_end')->nullable();
            $t->string('status', 12)->default('requested')->index();
            $t->unsignedBigInteger('decided_by')->nullable();
            $t->string('decision_note', 255)->nullable();
            $t->dateTime('out_at')->nullable();
            $t->unsignedInteger('out_km')->nullable();
            $t->dateTime('back_at')->nullable();
            $t->unsignedInteger('back_km')->nullable();
            $t->string('fuel_level', 10)->nullable();
            $t->text('notes')->nullable();
            $t->text('report')->nullable();
            $t->dateTime('reminded_at')->nullable();
            $t->timestamps();
        });
        Schema::create('fl_fuel', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('title', 200)->nullable();
            $t->unsignedBigInteger('vehicle_id')->index();
            $t->unsignedBigInteger('trip_id')->nullable();
            $t->unsignedBigInteger('driver_id')->nullable();
            $t->date('date')->index();
            $t->decimal('liters', 10, 2)->nullable();
            $t->decimal('amount', 14, 2);
            $t->unsignedInteger('odometer')->nullable();
            $t->string('station', 120)->nullable();
            $t->string('payment', 12)->default('cash');
            $t->boolean('full_tank')->default(true);
            $t->string('receipt_url', 500)->nullable();
            $t->timestamps();
        });
        Schema::create('fl_costs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('title', 200);
            $t->unsignedBigInteger('vehicle_id')->index();
            $t->string('kind', 15)->default('service');
            $t->date('date')->index();
            $t->decimal('amount', 14, 2)->nullable();
            $t->unsignedInteger('odometer')->nullable();
            $t->string('garage', 120)->nullable();
            $t->date('next_date')->nullable();
            $t->unsignedInteger('next_km')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['fl_costs', 'fl_fuel', 'fl_trips', 'fl_vehicles'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
