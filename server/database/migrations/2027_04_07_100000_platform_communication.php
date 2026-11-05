<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Communication de l'éditeur vers ses sociétés clientes : campagnes (notification dans l'application et / ou e-mail,
 * texte, blocs ou HTML, immédiates, programmées ou récurrentes) et relances automatiques (fin d'essai, échéance…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_campaigns', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->json('channels');                       // ['notification', 'email']
            $t->json('audience');                       // segment, plans, statuts, destinataires…
            $t->string('subject')->nullable();          // e-mail
            $t->string('preheader')->nullable();
            $t->string('title')->nullable();            // notification
            $t->text('body_text')->nullable();          // texte simple (notification + e-mail « texte »)
            $t->string('format', 10)->default('text');  // text | design | html
            $t->json('blocks')->nullable();
            $t->json('style')->nullable();
            $t->longText('html')->nullable();
            $t->string('cta_label', 80)->nullable();
            $t->string('cta_url', 500)->nullable();
            $t->string('schedule', 12)->default('now'); // now | once | recurring
            $t->timestamp('send_at')->nullable();
            $t->json('recurrence')->nullable();         // {freq: daily|weekly|monthly, time, weekday, day, until}
            $t->timestamp('next_run_at')->nullable()->index();
            $t->timestamp('last_run_at')->nullable();
            $t->string('status', 12)->default('draft')->index(); // draft | scheduled | sending | sent | paused
            $t->unsignedInteger('runs')->default(0);
            $t->json('stats')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('platform_automations', function (Blueprint $t) {
            $t->id();
            $t->string('trigger', 40)->unique();        // trial_ending, trial_ended, subscription_ending…
            $t->string('name');
            $t->unsignedSmallInteger('days')->default(0);
            $t->json('channels');
            $t->string('subject')->nullable();
            $t->string('preheader')->nullable();
            $t->string('title')->nullable();
            $t->text('body_text')->nullable();
            $t->string('format', 10)->default('text');
            $t->json('blocks')->nullable();
            $t->json('style')->nullable();
            $t->longText('html')->nullable();
            $t->string('cta_label', 80)->nullable();
            $t->string('cta_url', 500)->nullable();
            $t->string('recipients', 12)->default('admins'); // admins | all
            $t->boolean('is_active')->default(false);
            $t->unsignedInteger('sent')->default(0);
            $t->timestamp('last_run_at')->nullable();
            $t->timestamps();
        });

        Schema::create('platform_deliveries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campaign_id')->nullable()->index();
            $t->foreignId('automation_id')->nullable()->index();
            $t->unsignedInteger('run')->default(1);
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->string('channel', 12);                  // notification | email
            $t->string('email', 190)->nullable();
            $t->string('token', 48)->unique();
            $t->string('status', 12)->default('pending')->index(); // pending | sent | failed | skipped
            $t->string('error', 255)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('clicked_at')->nullable();
            $t->unsignedSmallInteger('clicks')->default(0);
            $t->timestamps();
        });

        // Une relance n'est envoyée qu'une fois par société et par occurrence (ex. une date de fin d'essai donnée)
        Schema::create('platform_automation_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('automation_id');
            $t->foreignId('company_id');
            $t->string('occurrence', 60);
            $t->timestamp('created_at')->nullable();
            $t->unique(['automation_id', 'company_id', 'occurrence'], 'pal_unique');
        });

        Schema::create('platform_unsubscribes', function (Blueprint $t) {
            $t->id();
            $t->string('email', 190)->unique();
            $t->foreignId('company_id')->nullable();
            $t->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['platform_unsubscribes', 'platform_automation_logs', 'platform_deliveries', 'platform_automations', 'platform_campaigns'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
