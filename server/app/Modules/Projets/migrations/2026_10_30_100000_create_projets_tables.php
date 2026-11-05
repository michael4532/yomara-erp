<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prj_projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('code', 30)->nullable();
            $table->uuid('partner_id')->nullable()->index();
            $table->uuid('lead_id')->nullable()->index();
            $table->foreignId('manager_id')->nullable()->index();
            $table->string('status', 15)->default('active'); // planned, active, on_hold, done, cancelled
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('billing_mode', 15)->default('time'); // time, fixed, none
            $table->decimal('hourly_rate', 15, 2)->default(0);
            $table->decimal('budget_amount', 15, 2)->default(0);
            $table->decimal('budget_hours', 10, 2)->default(0);
            $table->string('color', 9)->nullable();
            $table->text('description')->nullable();
            $table->json('member_ids')->nullable();
            $table->string('tags')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prj_milestones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('project_id')->index();
            $table->string('name');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('status', 15)->default('open'); // open, reached, invoiced
            $table->uuid('invoice_id')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('prj_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('milestone_id')->nullable()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status', 10)->default('todo'); // todo, doing, review, done
            $table->unsignedTinyInteger('priority')->default(0);
            $table->foreignId('assignee_id')->nullable()->index();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('estimate_hours', 8, 2)->default(0);
            $table->double('sort')->default(0);
            $table->json('checklist')->nullable();
            $table->string('tags')->nullable();
            $table->string('blocked_reason')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'status']);
        });

        Schema::create('prj_timesheets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('company_id')->index();
            $table->uuid('project_id')->index();
            $table->uuid('task_id')->nullable()->index();
            $table->foreignId('user_id')->index();
            $table->date('date');
            $table->decimal('hours', 6, 2);
            $table->string('description')->nullable();
            $table->boolean('billable')->default(true);
            $table->uuid('invoice_id')->nullable()->index();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['prj_timesheets', 'prj_tasks', 'prj_milestones', 'prj_projects'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
