<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cm_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('title', 200)->nullable();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('period_kind', 10)->default('month');
            $t->date('period_start')->index();
            $t->date('period_end')->nullable();
            $t->decimal('revenue_target', 16, 2)->nullable();
            $t->unsignedInteger('visits_target')->nullable();
            $t->unsignedInteger('customers_target')->nullable();
            $t->unsignedInteger('quotes_target')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('cm_tours', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('title', 200);
            $t->unsignedBigInteger('user_id')->index();
            $t->date('date')->index();
            $t->string('zone', 200)->nullable();
            $t->string('status', 12)->default('planned');
            $t->unsignedBigInteger('trip_id')->nullable();
            $t->text('notes')->nullable();
            $t->text('summary')->nullable();
            $t->timestamps();
        });
        Schema::create('cm_visits', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('title', 200);
            $t->unsignedBigInteger('user_id')->index();
            $t->unsignedBigInteger('tour_id')->nullable()->index();
            $t->string('partner_id', 40)->nullable()->index();
            $t->string('prospect', 200)->nullable();
            $t->string('contact', 200)->nullable();
            $t->string('address', 255)->nullable();
            $t->string('kind', 12)->default('visit');
            $t->dateTime('planned_at')->nullable()->index();
            $t->string('status', 12)->default('planned')->index();
            $t->dateTime('done_at')->nullable();
            $t->string('outcome', 15)->nullable();
            $t->decimal('amount', 16, 2)->nullable();
            $t->text('report')->nullable();
            $t->string('next_step', 255)->nullable();
            $t->date('next_date')->nullable();
            $t->string('location', 120)->nullable();
            $t->string('follow_up_id', 40)->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['cm_visits', 'cm_tours', 'cm_targets'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
