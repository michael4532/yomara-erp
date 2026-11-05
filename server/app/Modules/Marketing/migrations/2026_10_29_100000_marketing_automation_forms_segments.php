<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Marketing v2 : segments, formulaires et pages, automatisations, test A/B, carte des clics, score. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mk_contacts', function (Blueprint $table) {
            $table->date('birthday')->nullable();
            $table->string('city', 120)->nullable();
            $table->json('fields')->nullable(); // attributs libres
            $table->integer('score')->default(0);
            $table->string('opt_in', 20)->nullable(); // pending (double opt-in en attente), confirmed
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_ip', 45)->nullable();
            $table->timestamp('last_activity_at')->nullable();
        });

        Schema::table('mk_campaigns', function (Blueprint $table) {
            $table->json('segment_ids')->nullable();
            $table->json('ab')->nullable(); // {enabled, subject_b, percent, wait_hours, metric, status, decide_at, winner}
        });

        Schema::table('mk_messages', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->change();
            $table->foreignId('automation_id')->nullable()->index();
            $table->string('step_id', 40)->nullable();
            $table->char('variant', 1)->nullable();
        });

        Schema::create('mk_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('message_id')->index();
            $table->foreignId('campaign_id')->nullable()->index();
            $table->foreignId('automation_id')->nullable()->index();
            $table->string('url', 2000);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('mk_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('match', 5)->default('all'); // all, any
            $table->json('conditions')->nullable();
            $table->timestamps();
        });

        Schema::create('mk_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('kind', 10)->default('form'); // form, page
            $table->string('name');
            $table->string('slug', 80)->unique();
            $table->foreignId('list_id')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->json('fields')->nullable();       // champs demandés et obligatoires
            $table->string('button_label')->nullable();
            $table->boolean('double_optin')->default(true);
            $table->text('consent_text')->nullable();
            $table->text('success_message')->nullable();
            $table->string('redirect_url')->nullable();
            $table->json('blocks')->nullable();       // contenu de la page de destination
            $table->json('style')->nullable();
            $table->boolean('with_form')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('submissions')->default(0);
            $table->timestamps();
        });

        Schema::create('mk_automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('trigger', 30);           // list_join, form, tag, birthday, manual
            $table->json('trigger_config')->nullable();
            $table->json('steps')->nullable();
            $table->string('status', 10)->default('draft'); // draft, active, paused
            $table->boolean('allow_reentry')->default(false);
            $table->json('stats')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('mk_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('automation_id')->index();
            $table->foreignId('contact_id')->index();
            $table->string('cursor', 120)->default('0');
            $table->string('status', 10)->default('active'); // active, done, exited, failed
            $table->timestamp('next_run_at')->nullable()->index();
            $table->foreignId('last_message_id')->nullable();
            $table->json('history')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['mk_automation_runs', 'mk_automations', 'mk_forms', 'mk_segments', 'mk_clicks'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('mk_messages', fn (Blueprint $t) => $t->dropColumn(['automation_id', 'step_id', 'variant']));
        Schema::table('mk_campaigns', fn (Blueprint $t) => $t->dropColumn(['segment_ids', 'ab']));
        Schema::table('mk_contacts', fn (Blueprint $t) => $t->dropColumn(['birthday', 'city', 'fields', 'score', 'opt_in', 'consent_at', 'consent_ip', 'last_activity_at']));
    }
};
