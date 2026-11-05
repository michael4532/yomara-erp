<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notify_channels', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('kind', 12);
            $t->string('name', 120);
            $t->text('config');
            $t->json('events')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamp('last_sent_at')->nullable();
            $t->string('last_error', 500)->nullable();
            $t->unsignedInteger('sent_count')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notify_channels');
    }
};
