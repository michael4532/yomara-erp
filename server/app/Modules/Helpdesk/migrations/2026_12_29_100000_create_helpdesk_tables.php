<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sync = function (Blueprint $t) {
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        };
        Schema::create('hd_sla_policies', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->json('targets'); // {"urgent":{"first":30,"resolve":240}, ...} en minutes
            $t->boolean('business_hours')->default(true);
            $t->boolean('is_default')->default(false);
            $t->json('partner_ids')->nullable();
            $sync($t);
        });
        Schema::create('hd_categories', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name');
            $t->string('color', 9)->nullable();
            $t->unsignedBigInteger('assignee_id')->nullable();
            $t->boolean('public')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $sync($t);
        });
        Schema::create('hd_tickets', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->uuid('partner_id')->nullable()->index();
            $t->string('partner_name')->nullable();
            $t->string('contact_name', 120)->nullable();
            $t->string('contact_email')->nullable();
            $t->string('contact_phone', 40)->nullable();
            $t->string('subject');
            $t->text('description')->nullable();
            $t->string('channel', 12)->default('agent'); // agent, phone, email, whatsapp, web, portal
            $t->uuid('category_id')->nullable();
            $t->string('priority', 8)->default('normal'); // low, normal, high, urgent
            $t->string('status', 10)->default('new'); // new, open, pending, resolved, closed
            $t->unsignedBigInteger('assignee_id')->nullable()->index();
            $t->uuid('sla_policy_id')->nullable();
            $t->timestamp('first_response_due')->nullable();
            $t->timestamp('resolution_due')->nullable();
            $t->timestamp('first_response_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('pending_since')->nullable();
            $t->unsignedInteger('paused_minutes')->default(0);
            $t->boolean('sla_first_breached')->default(false);
            $t->boolean('sla_resolve_breached')->default(false);
            $t->uuid('pack_id')->nullable();
            $t->string('tags')->nullable();
            $t->unsignedTinyInteger('rating')->nullable();
            $t->text('rating_comment')->nullable();
            $t->unsignedSmallInteger('reopened')->default(0);
            $t->timestamp('last_customer_at')->nullable();
            $t->timestamp('last_agent_at')->nullable();
            $t->string('token', 48)->nullable()->unique();
            $sync($t);
            $t->index(['company_id', 'status']);
        });
        Schema::create('hd_messages', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('ticket_id')->index();
            $t->string('author_type', 10); // agent, customer, system
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('author_name', 120)->nullable();
            $t->text('body');
            $t->boolean('internal')->default(false);
            $t->string('sent_via', 40)->nullable();
            $sync($t);
        });
        Schema::create('hd_times', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('ticket_id')->index();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('user_name', 120)->nullable();
            $t->date('date');
            $t->unsignedSmallInteger('minutes');
            $t->string('note')->nullable();
            $t->boolean('billable')->default(true);
            $sync($t);
        });
        Schema::create('hd_articles', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('title');
            $t->string('slug', 160);
            $t->string('section', 80)->nullable();
            $t->text('body');
            $t->boolean('published')->default(false);
            $t->string('tags')->nullable();
            $t->unsignedInteger('views')->default(0);
            $t->unsignedInteger('helpful_yes')->default(0);
            $t->unsignedInteger('helpful_no')->default(0);
            $t->string('author_name', 120)->nullable();
            $sync($t);
            $t->index(['company_id', 'slug']);
        });
        Schema::create('hd_macros', function (Blueprint $t) use ($sync) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('name', 120);
            $t->text('body');
            $t->string('set_status', 10)->nullable();
            $sync($t);
        });
        Schema::table('pr_entries', function (Blueprint $t) {
            $t->uuid('ticket_id')->nullable()->index();
            $t->uuid('ticket_time_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('pr_entries', fn (Blueprint $t) => $t->dropColumn(['ticket_id', 'ticket_time_id']));
        foreach (['hd_macros', 'hd_articles', 'hd_times', 'hd_messages', 'hd_tickets', 'hd_categories', 'hd_sla_policies'] as $tb) {
            Schema::dropIfExists($tb);
        }
    }
};
