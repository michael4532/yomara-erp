<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Rappels envoyés avant une échéance : un seul par échéance
        Schema::create('rl_upcoming', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('document_id');
            $t->date('due_date');
            $t->foreignId('reminder_id')->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rl_upcoming');
    }
};
