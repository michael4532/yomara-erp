<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_accesses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->uuid('partner_id')->index();
            $table->string('token', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestamp('invited_at')->nullable();
            $table->string('invited_via', 20)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedInteger('login_count')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'partner_id']);
        });

        Schema::create('portal_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->uuid('partner_id')->index();
            $table->string('type', 30); // login, view, quote_accepted, quote_refused, message, reply
            $table->uuid('document_id')->nullable()->index();
            $table->json('data')->nullable();
            $table->foreignId('user_id')->nullable(); // réponse d'un membre de l'équipe
            $table->string('ip', 45)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_events');
        Schema::dropIfExists('portal_accesses');
    }
};
