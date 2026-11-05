<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tt_weeks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('user_name', 120)->nullable();
            $t->date('week_start');
            $t->string('status', 10)->default('draft'); // draft, submitted, approved, rejected
            $t->decimal('hours', 7, 2)->default(0);
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decided_by', 120)->nullable();
            $t->text('comment')->nullable();
            $t->json('history')->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tt_weeks');
    }
};
