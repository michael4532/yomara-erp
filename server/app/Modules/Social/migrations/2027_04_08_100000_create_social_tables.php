<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('so_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('network', 20);
            $t->string('external_id', 191);
            $t->string('name');
            $t->string('username')->nullable();
            $t->string('avatar_url', 1000)->nullable();
            $t->string('kind', 30)->nullable(); // page, profile, organization, board, location, channel…
            $t->text('credentials'); // chiffré : jetons, jeton de rafraîchissement, échéance, paramètres
            $t->json('meta')->nullable();
            $t->string('status', 12)->default('active'); // active, error, expired, disabled
            $t->string('last_error', 500)->nullable();
            $t->timestamp('token_expires_at')->nullable();
            $t->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'network', 'external_id']);
        });

        Schema::create('so_media', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 10); // image, video
            $t->string('path');
            $t->string('mime', 60);
            $t->unsignedBigInteger('size')->default(0);
            $t->unsignedInteger('width')->nullable();
            $t->unsignedInteger('height')->nullable();
            $t->string('original_name')->nullable();
            $t->timestamps();
        });

        Schema::create('so_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('title')->nullable();
            $t->text('body')->nullable();
            $t->string('link_url', 1000)->nullable();
            $t->json('media')->nullable();      // ids de so_media, dans l'ordre
            $t->json('variants')->nullable();   // { réseau: { text, title } } : texte propre à un réseau
            $t->json('options')->nullable();    // { tiktok_privacy, first_comment, pinterest_board… }
            $t->string('status', 20)->default('draft'); // draft, pending, scheduled, publishing, published, partial, failed, cancelled
            $t->timestamp('scheduled_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->string('campaign', 120)->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->string('rejection', 500)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status', 'scheduled_at']);
        });

        Schema::create('so_targets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('so_posts')->cascadeOnDelete();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->constrained('so_accounts')->cascadeOnDelete();
            $t->string('network', 20);
            $t->string('status', 12)->default('pending'); // pending, publishing, published, failed
            $t->string('external_id', 191)->nullable();
            $t->string('url', 1000)->nullable();
            $t->string('error', 500)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('retry_at')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->json('stats')->nullable();
            $t->timestamp('stats_at')->nullable();
            $t->timestamps();
            $t->unique(['post_id', 'account_id']);
        });

        Schema::create('so_oauth_states', function (Blueprint $t) {
            $t->string('state', 64)->primary();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('provider', 20);
            $t->string('verifier', 128)->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('so_oauth_states');
        Schema::dropIfExists('so_targets');
        Schema::dropIfExists('so_posts');
        Schema::dropIfExists('so_media');
        Schema::dropIfExists('so_accounts');
    }
};
