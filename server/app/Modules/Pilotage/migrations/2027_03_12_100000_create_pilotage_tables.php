<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pl_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('title', 60);
            $t->unsignedSmallInteger('year');
            $t->unsignedTinyInteger('month')->nullable();
            $t->decimal('revenue', 16, 2)->default(0);
            $t->decimal('margin', 16, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pl_targets');
    }
};
