<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $base = function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable()->index();
        };
        Schema::create('vc_competitors', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->string('kind', 12)->default('direct');
            $t->string('status', 10)->default('watch')->index();
            $t->string('threat', 8)->default('medium');
            $t->string('positioning', 8)->nullable();
            $t->string('sector', 150)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('website', 500)->nullable();
            $t->string('phone', 60)->nullable();
            foreach (['facebook', 'instagram', 'tiktok', 'linkedin', 'youtube', 'x_account', 'google_maps'] as $c) {
                $t->string($c, 500)->nullable();
            }
            $t->decimal('rating', 3, 1)->nullable();
            $t->unsignedInteger('reviews_count')->nullable();
            $t->decimal('market_share', 5, 2)->nullable();
            $t->decimal('revenue_estimate', 16, 2)->nullable();
            $t->unsignedInteger('employees')->nullable();
            foreach (['score_price', 'score_quality', 'score_service', 'score_range', 'score_digital', 'score_reputation'] as $c) {
                $t->unsignedTinyInteger($c)->nullable();
            }
            foreach (['offer', 'strengths', 'weaknesses', 'opportunities', 'threats', 'battle_card', 'notes'] as $c) {
                $t->text($c)->nullable();
            }
            $t->json('site_audit')->nullable();
            $t->dateTime('audit_at')->nullable();
            $t->timestamps();
        });
        Schema::create('vc_observations', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 250);
            $t->unsignedBigInteger('competitor_id')->nullable()->index();
            $t->date('observed_on')->nullable()->index();
            $t->string('kind', 12)->default('campaign');
            $t->string('channel', 12)->nullable();
            $t->string('impact', 8)->default('medium');
            $t->string('status', 10)->default('new')->index();
            $t->string('source', 8)->default('manual');
            $t->string('location', 200)->nullable();
            $t->text('details')->nullable();
            $t->string('source_url', 500)->nullable();
            $t->text('response')->nullable();
            $t->string('photo_path', 255)->nullable();
            $t->string('photo_name', 150)->nullable();
            $t->timestamps();
        });
        Schema::create('vc_prices', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->unsignedBigInteger('competitor_id')->index();
            $t->string('our_product', 200)->nullable();
            $t->uuid('product_id')->nullable()->index();
            $t->decimal('competitor_price', 16, 2);
            $t->decimal('our_price', 16, 2)->nullable();
            $t->boolean('promo')->default(false);
            $t->date('observed_on')->nullable()->index();
            $t->string('source', 12)->default('store');
            $t->string('source_url', 500)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('vc_ads', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 250);
            $t->unsignedBigInteger('competitor_id')->nullable()->index();
            $t->string('platform', 12)->default('meta');
            $t->string('format', 12)->nullable();
            $t->date('started_on')->nullable();
            $t->date('ended_on')->nullable();
            $t->boolean('active')->default(true);
            $t->string('offer', 250)->nullable();
            $t->string('cta', 120)->nullable();
            $t->string('target', 250)->nullable();
            $t->string('landing_url', 500)->nullable();
            $t->string('library_url', 500)->nullable();
            $t->decimal('budget_estimate', 16, 2)->nullable();
            $t->text('takeaways')->nullable();
            $t->timestamps();
        });
        Schema::create('vc_audience', function (Blueprint $t) use ($base) {
            $base($t);
            $t->unsignedBigInteger('competitor_id')->index();
            $t->string('network', 12);
            $t->unsignedBigInteger('followers');
            $t->date('recorded_on')->index();
            $t->decimal('engagement', 6, 2)->nullable();
            $t->unsignedInteger('posts_month')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('vc_watches', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->unsignedBigInteger('competitor_id')->nullable()->index();
            $t->string('url', 500);
            $t->string('kind', 10)->default('home');
            $t->string('frequency', 8)->default('daily');
            $t->string('keywords', 500)->nullable();
            $t->boolean('active')->default(true);
            $t->dateTime('last_checked_at')->nullable();
            $t->dateTime('last_changed_at')->nullable();
            $t->string('last_hash', 64)->nullable();
            $t->mediumText('last_text')->nullable();
            $t->string('last_error', 250)->nullable();
            $t->text('last_change')->nullable();
            $t->unsignedInteger('changes')->default(0);
            $t->timestamps();
        });
        Schema::create('vc_alerts', function (Blueprint $t) use ($base) {
            $base($t);
            $t->string('title', 200);
            $t->unsignedBigInteger('competitor_id')->nullable()->index();
            $t->string('feed_url', 500)->nullable();
            $t->boolean('google_news')->default(true);
            $t->boolean('active')->default(true);
            $t->dateTime('last_checked_at')->nullable();
            $t->string('last_error', 250)->nullable();
            $t->timestamps();
        });
        Schema::create('vc_mentions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->unsignedBigInteger('alert_id')->nullable()->index();
            $t->unsignedBigInteger('competitor_id')->nullable()->index();
            $t->string('title', 300);
            $t->string('url', 1000);
            $t->char('url_hash', 40);
            $t->string('source_name', 150)->nullable();
            $t->dateTime('published_at')->nullable()->index();
            $t->string('status', 8)->default('new')->index();
            $t->text('summary')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'url_hash']);
        });
    }

    public function down(): void
    {
        foreach (['vc_mentions', 'vc_alerts', 'vc_watches', 'vc_audience', 'vc_ads', 'vc_prices', 'vc_observations', 'vc_competitors'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
