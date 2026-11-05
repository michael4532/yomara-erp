<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ac_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('platform', 12);
            $t->string('name', 150);
            $t->string('external_id', 64)->nullable();
            $t->text('credentials')->nullable(); // chiffré
            $t->string('currency', 3)->nullable();
            $t->string('pixel_id', 40)->nullable();
            $t->boolean('send_conversions')->default(false);
            $t->string('status', 10)->default('active');
            $t->string('last_error', 300)->nullable();
            $t->dateTime('synced_at')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('ac_campaigns', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->unsignedBigInteger('account_id')->nullable()->index();
            $t->string('platform', 12)->default('meta');
            $t->string('external_id', 64)->nullable();
            $t->string('title', 200);
            $t->string('utm_campaign', 150)->nullable()->index();
            $t->string('objective', 12)->nullable();
            $t->string('status', 10)->default('active');
            $t->date('started_on')->nullable();
            $t->date('ended_on')->nullable();
            $t->decimal('budget', 16, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'platform', 'external_id']);
        });
        Schema::create('ac_spend', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->unsignedBigInteger('campaign_id')->index();
            $t->date('date')->index();
            $t->decimal('amount', 16, 2)->default(0);
            $t->unsignedBigInteger('impressions')->nullable();
            $t->unsignedBigInteger('clicks')->nullable();
            $t->unsignedInteger('platform_leads')->nullable();
            $t->decimal('platform_conversions', 12, 2)->nullable();
            $t->string('source', 8)->default('manual');
            $t->timestamps();
            $t->unique(['campaign_id', 'date', 'source']);
        });
        Schema::create('ac_sources', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('title', 150);
            $t->string('platform', 12)->default('other');
            $t->char('token', 40)->unique();
            $t->string('secret', 64)->nullable();
            $t->string('match_ref', 64)->nullable()->index(); // page Facebook ou formulaire
            $t->string('assign', 12)->default('owner');
            $t->string('tags', 200)->nullable();
            $t->unsignedBigInteger('list_id')->nullable();
            $t->unsignedBigInteger('sequence_id')->nullable();
            $t->string('reply_channel', 10)->nullable();
            $t->text('reply_text')->nullable();
            $t->boolean('notify')->default(true);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('rr_index')->default(0);
            $t->timestamps();
        });
        Schema::create('ac_leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->unsignedBigInteger('source_id')->nullable()->index();
            $t->string('platform', 12);
            $t->string('external_id', 120)->nullable();
            $t->string('title', 200);
            $t->string('email', 190)->nullable();
            $t->string('phone', 50)->nullable();
            $t->unsignedBigInteger('campaign_id')->nullable()->index();
            $t->string('ad_name', 200)->nullable();
            $t->string('form_name', 200)->nullable();
            $t->json('fields')->nullable();
            $t->uuid('lead_id')->nullable()->index();
            $t->string('status', 10)->default('created');
            $t->string('note', 300)->nullable();
            $t->dateTime('received_at')->index();
            $t->timestamps();
            $t->unique(['company_id', 'platform', 'external_id']);
        });
        Schema::create('ac_pages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('owner_id')->nullable();
            $t->string('title', 150);
            $t->string('slug', 80)->unique();
            $t->string('status', 10)->default('draft');
            $t->string('headline', 200)->nullable();
            $t->string('subheadline', 300)->nullable();
            $t->text('body')->nullable();
            $t->text('bullets')->nullable();
            $t->string('image_url', 500)->nullable();
            $t->string('color', 9)->nullable();
            $t->string('cta_label', 80)->nullable();
            $t->string('fields', 200)->nullable();
            $t->string('offer', 200)->nullable();
            $t->dateTime('deadline')->nullable();
            $t->text('thank_you')->nullable();
            $t->string('redirect_url', 500)->nullable();
            $t->string('whatsapp', 30)->nullable();
            $t->unsignedBigInteger('source_id')->nullable();
            $t->unsignedInteger('views')->default(0);
            $t->timestamps();
        });
        Schema::create('ac_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('platform', 12);
            $t->string('event', 30);
            $t->string('ref', 120)->nullable();
            $t->decimal('value', 16, 2)->nullable();
            $t->string('status', 8);
            $t->string('response', 300)->nullable();
            $t->timestamps();
            $t->index(['company_id', 'ref']);
        });
    }

    public function down(): void
    {
        foreach (['ac_events', 'ac_pages', 'ac_leads', 'ac_sources', 'ac_spend', 'ac_campaigns', 'ac_accounts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
