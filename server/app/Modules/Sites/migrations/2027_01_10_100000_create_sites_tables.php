<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ws_sites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('name', 160);
            $t->string('slug', 80)->unique();
            $t->string('template', 60);
            $t->string('status', 12)->default('draft'); // draft, published, suspended
            $t->json('theme')->nullable();
            $t->json('content')->nullable();
            $t->json('sections')->nullable();
            $t->json('seo')->nullable();
            $t->string('preview_token', 48);
            $t->string('custom_domain', 190)->nullable()->unique();
            $t->string('domain_status', 12)->default('none'); // none, pending, verified, active, error
            $t->string('domain_token', 40)->nullable();
            $t->timestamp('domain_checked_at')->nullable();
            $t->string('domain_error')->nullable();
            $t->unsignedBigInteger('request_id')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ws_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('number', 30)->nullable();
            $t->string('type', 10); // template, custom
            $t->string('template', 60)->nullable();
            $t->string('site_name', 160);
            $t->string('sector', 40)->nullable();
            $t->json('needs')->nullable();
            $t->decimal('estimate', 14, 2)->default(0);
            $t->string('status', 12)->default('submitted'); // submitted, quoted, accepted, refused, in_progress, delivered, rejected, cancelled
            $t->json('quote')->nullable();
            $t->text('admin_note')->nullable();
            $t->unsignedBigInteger('site_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ws_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('site_id')->index();
            $t->string('name', 160);
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('subject', 190)->nullable();
            $t->text('message');
            $t->boolean('consent')->default(false);
            $t->string('lead_id', 64)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
        Schema::create('ws_visits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('site_id');
            $t->date('date');
            $t->unsignedInteger('views')->default(0);
            $t->json('referrers')->nullable();
            $t->unique(['site_id', 'date']);
        });
    }

    public function down(): void
    {
        foreach (['ws_visits', 'ws_messages', 'ws_requests', 'ws_sites'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
