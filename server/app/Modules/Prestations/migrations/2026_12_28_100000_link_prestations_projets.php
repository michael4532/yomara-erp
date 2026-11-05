<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prj_projects', fn (Blueprint $t) => $t->uuid('pack_id')->nullable()->index());
        Schema::table('prj_timesheets', fn (Blueprint $t) => $t->uuid('pack_id')->nullable()->index());
        Schema::table('pr_entries', function (Blueprint $t) {
            $t->uuid('timesheet_id')->nullable()->index();
            $t->string('project_name')->nullable();
        });
        Schema::create('pr_reports', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('partner_id')->index();
            $t->string('partner_name')->nullable();
            $t->string('period', 7);
            $t->float('hours')->default(0);
            $t->unsignedInteger('deliverables')->default(0);
            $t->string('token', 40)->unique();
            $t->string('file_path')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->string('sent_to')->nullable();
            $t->string('created_by')->nullable();
            $t->unsignedInteger('version')->default(1);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'partner_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pr_reports');
        Schema::table('pr_entries', fn (Blueprint $t) => $t->dropColumn(['timesheet_id', 'project_name']));
        Schema::table('prj_timesheets', fn (Blueprint $t) => $t->dropColumn('pack_id'));
        Schema::table('prj_projects', fn (Blueprint $t) => $t->dropColumn('pack_id'));
    }
};
