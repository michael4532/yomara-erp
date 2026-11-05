<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ia_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique();
            $t->string('provider', 20)->nullable();   // null = fournisseur de la plateforme
            $t->string('model', 100)->nullable();
            $t->string('base_url')->nullable();
            $t->text('api_key')->nullable();          // chiffrée
            $t->unsignedInteger('monthly_limit')->nullable();
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('ia_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->index();
            $t->string('title', 190)->nullable();
            $t->timestamps();
        });
        Schema::create('ia_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversation_id')->nullable()->index();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->string('role', 12);                   // user, assistant, draft
            $t->longText('content');
            $t->json('tools')->nullable();            // outils appelés (nom, arguments, résumé)
            $t->unsignedInteger('tokens_in')->default(0);
            $t->unsignedInteger('tokens_out')->default(0);
            $t->timestamps();
            $t->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ia_messages');
        Schema::dropIfExists('ia_conversations');
        Schema::dropIfExists('ia_settings');
    }
};
