<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dev_apps', function (Blueprint $t) {
            $t->string('git_provider', 10)->nullable();
            $t->string('git_token', 40)->nullable()->unique();
            $t->string('git_secret', 64)->nullable();
            $t->string('on_merge', 10)->default('testing');
            $t->timestamp('git_last_event_at')->nullable();
        });
        Schema::create('dev_item_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('item_id')->index();
            $t->string('kind', 12);
            $t->string('title', 255);
            $t->string('url', 500)->nullable();
            $t->string('author', 120)->nullable();
            $t->string('ref', 120)->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dev_item_events');
        Schema::table('dev_apps', fn (Blueprint $t) => $t->dropColumn(['git_provider', 'git_token', 'git_secret', 'on_merge', 'git_last_event_at']));
    }
};
