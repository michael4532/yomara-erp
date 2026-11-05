<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_assignments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('user_name', 120)->nullable();
            $t->uuid('project_id')->nullable()->index();
            $t->uuid('partner_id')->nullable();
            $t->string('partner_name')->nullable();
            $t->string('title');
            $t->date('start_date');
            $t->date('end_date');
            $t->decimal('hours_per_day', 5, 2)->default(8);
            $t->string('status', 10)->default('confirmed'); // confirmed, tentative
            $t->string('color', 9)->nullable();
            $t->text('notes')->nullable();
            $t->string('created_by', 120)->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->index(['company_id', 'start_date', 'end_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_assignments');
    }
};
