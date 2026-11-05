<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Console éditeur : activité quotidienne des utilisateurs, échantillons des connectés en temps réel,
 * et support client (conversations en direct entre les sociétés et l'éditeur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_activity', function (Blueprint $t) {
            $t->id();
            $t->date('day');
            $t->unsignedBigInteger('user_id')->index(); // pas de clé étrangère : restauration de sauvegarde dans n'importe quel ordre
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->string('platform', 30)->nullable();
            $t->timestamp('first_at')->nullable();
            $t->timestamp('last_at')->nullable();
            $t->unsignedInteger('hits')->default(0);
            $t->unique(['day', 'user_id']);
            $t->index(['day', 'company_id']);
        });
        Schema::create('platform_online_samples', function (Blueprint $t) {
            $t->id();
            $t->timestamp('at')->index();
            $t->unsignedInteger('users')->default(0);
            $t->unsignedInteger('companies')->default(0);
        });
        Schema::create('support_conversations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('subject', 160);
            $t->string('category', 30)->default('question');
            $t->string('priority', 10)->default('normal');
            $t->string('status', 20)->default('open'); // open, waiting, closed
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedInteger('unread_company')->default(0);
            $t->unsignedInteger('unread_platform')->default(0);
            $t->timestamp('last_message_at')->nullable()->index();
            $t->unsignedTinyInteger('rating')->nullable();
            $t->timestamps();
        });
        Schema::create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('conversation_id')->index();
            $t->unsignedBigInteger('company_id')->index();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->boolean('from_platform')->default(false);
            $t->text('body');
            $t->json('meta')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_conversations');
        Schema::dropIfExists('platform_online_samples');
        Schema::dropIfExists('platform_activity');
    }
};
