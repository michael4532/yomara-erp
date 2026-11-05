<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mc_social_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('platform', 12);
            $t->string('name', 200);
            $t->string('external_id', 100);
            $t->string('username', 100)->nullable();
            $t->text('credentials');
            $t->string('status', 10)->default('active');
            $t->string('last_error', 500)->nullable();
            $t->unsignedBigInteger('connected_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'platform', 'external_id']);
        });
        Schema::table('mc_posts', function (Blueprint $t) {
            $t->boolean('auto_publish')->default(false);
            $t->json('social')->nullable();
            $t->timestamp('stats_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('mc_posts', fn (Blueprint $t) => $t->dropColumn(['auto_publish', 'social', 'stats_synced_at']));
        Schema::dropIfExists('mc_social_accounts');
    }
};
