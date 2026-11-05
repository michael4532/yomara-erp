<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tâches personnelles (« Ma journée ») : propres à chaque utilisateur
        Schema::create('user_todos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->nullable()->index();
            $t->foreignId('user_id')->index();
            $t->string('title', 255);
            $t->date('due_date')->nullable();
            $t->boolean('important')->default(false);
            $t->boolean('my_day')->default(true);
            $t->timestamp('done_at')->nullable();
            $t->unsignedInteger('sort')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_todos');
    }
};
