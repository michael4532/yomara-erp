<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('so_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('account_id')->index();
            $t->string('network', 20);
            $t->string('kind', 10); // comment, message
            $t->string('thread_ref', 191);
            $t->string('post_ref', 191)->nullable();
            $t->string('post_text', 300)->nullable();
            $t->string('author_ref', 120)->nullable();
            $t->string('author_name', 150)->nullable();
            $t->string('last_text', 500)->nullable();
            $t->dateTime('last_at')->nullable()->index();
            $t->string('status', 8)->default('open')->index();
            $t->unsignedSmallInteger('unread')->default(0);
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->uuid('lead_id')->nullable();
            $t->timestamps();
            $t->unique(['account_id', 'kind', 'thread_ref']);
        });
        Schema::create('so_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('conversation_id')->index();
            $t->string('external_id', 191)->nullable();
            $t->string('direction', 3); // in, out
            $t->string('author_name', 150)->nullable();
            $t->text('text')->nullable();
            $t->boolean('hidden')->default(false);
            $t->boolean('private')->default(false);
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->dateTime('sent_at')->index();
            $t->timestamps();
            $t->unique(['company_id', 'external_id']);
        });
        Schema::table('so_accounts', function (Blueprint $t) {
            $t->dateTime('inbox_polled_at')->nullable();
            $t->boolean('inbox_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('so_messages');
        Schema::dropIfExists('so_conversations');
        Schema::table('so_accounts', fn (Blueprint $t) => $t->dropColumn(['inbox_polled_at', 'inbox_enabled']));
    }
};
