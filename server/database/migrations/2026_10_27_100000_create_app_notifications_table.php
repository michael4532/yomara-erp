<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notifications dans l'application (cloche) — tous modules
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('user_id')->index();
            $table->string('type', 50);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('module', 30)->nullable();
            $table->string('ref', 64)->nullable(); // identifiant de l'objet (ticket, commande…)
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
