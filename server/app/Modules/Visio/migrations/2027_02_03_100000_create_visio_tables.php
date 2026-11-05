<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Réglages de la société : identifiants ZEGOCLOUD / Agora (sinon ceux de la plateforme), ordre de priorité, quotas
        Schema::create('vis_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique();
            $t->string('zego_app_id', 20)->nullable();
            $t->text('zego_secret')->nullable(); // chiffré
            $t->string('zego_server', 190)->nullable();
            $t->string('agora_app_id', 40)->nullable();
            $t->text('agora_certificate')->nullable(); // chiffré
            $t->string('priority', 40)->default('zego,agora,p2p');
            $t->unsignedInteger('zego_budget_minutes')->default(0); // 0 = sans limite
            $t->unsignedInteger('agora_budget_minutes')->default(0);
            $t->boolean('p2p_fallback')->default(true);
            $t->string('turn_url', 190)->nullable();
            $t->string('turn_username', 120)->nullable();
            $t->text('turn_credential')->nullable();
            $t->json('defaults')->nullable(); // lobby, mute_on_entry, transcribe, record, ai_report, send_report
            $t->timestamps();
        });

        // État de santé des fournisseurs (bascule automatique)
        Schema::create('vis_provider_health', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('provider', 10);
            $t->unsignedInteger('failures')->default(0);
            $t->timestamp('down_until')->nullable();
            $t->string('last_reason', 190)->nullable();
            $t->timestamp('last_failure_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'provider']);
        });

        // Consommation (minutes-participant par fournisseur et par mois)
        Schema::create('vis_usage', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('provider', 10);
            $t->string('month', 7);
            $t->unsignedBigInteger('seconds')->default(0);
            $t->unsignedInteger('switches')->default(0);
            $t->timestamps();
            $t->unique(['company_id', 'provider', 'month']);
        });

        // Réunions et webinaires
        Schema::create('vis_meetings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('type', 10)->default('meeting'); // meeting, webinar
            $t->string('code', 16)->unique(); // adresse de la salle : abc-defg-hij
            $t->string('title', 190);
            $t->text('description')->nullable();
            $t->foreignId('host_id')->nullable()->index();
            $t->dateTime('starts_at')->nullable();
            $t->unsignedSmallInteger('duration_minutes')->default(60);
            $t->string('status', 10)->default('scheduled'); // scheduled, live, ended, cancelled
            $t->json('options')->nullable(); // lobby, guests, mute_on_entry, chat, screen, transcribe, record_auto, ai_report, send_report
            $t->string('password', 40)->nullable();
            $t->boolean('locked')->default(false);
            $t->string('provider', 10)->nullable(); // fournisseur actif
            $t->unsignedInteger('epoch')->default(0); // incrémenté à chaque bascule
            $t->unsignedInteger('switches')->default(0);
            $t->string('recording_state', 10)->nullable(); // recording, null
            $t->unsignedBigInteger('spotlight_id')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('ended_at')->nullable();
            $t->uuid('agenda_event_id')->nullable();
            $t->uuid('partner_id')->nullable();
            // Webinaire
            $t->string('slug', 80)->nullable()->unique();
            $t->unsignedInteger('capacity')->default(0); // 0 = illimité
            $t->boolean('registration_open')->default(true);
            $t->boolean('replay_public')->default(true);
            $t->boolean('create_leads')->default(false);
            $t->string('color', 9)->nullable();
            $t->json('reminders_sent')->nullable(); // {"24h": date, "1h": date}
            $t->timestamps();
            $t->index(['company_id', 'starts_at']);
            $t->index(['status', 'updated_at']);
        });

        // Participants : invités, inscrits aux webinaires, présence
        Schema::create('vis_participants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->index();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->nullable()->index();
            $t->string('name', 120);
            $t->string('email', 190)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('organization', 120)->nullable();
            $t->string('role', 10)->default('guest'); // host, cohost, panelist, attendee, guest, member
            $t->string('token', 48)->unique();
            $t->string('status', 10)->default('invited'); // invited, registered, lobby, admitted, left, denied, removed
            $t->boolean('mic')->default(false);
            $t->boolean('cam')->default(false);
            $t->boolean('screen')->default(false);
            $t->dateTime('hand_at')->nullable();
            $t->dateTime('first_joined_at')->nullable();
            $t->dateTime('joined_at')->nullable();
            $t->dateTime('left_at')->nullable();
            $t->dateTime('last_seen_at')->nullable();
            $t->unsignedInteger('seconds_total')->default(0);
            $t->string('client', 80)->nullable();
            $t->string('source', 60)->nullable(); // provenance de l'inscription (utm)
            $t->uuid('lead_id')->nullable();
            $t->dateTime('invited_at')->nullable();
            $t->timestamps();
            $t->index(['meeting_id', 'status']);
        });

        // Flux temps réel : conversation, sous-titres, réactions, signaux, consignes de l'hôte
        Schema::create('vis_feed', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id');
            $t->unsignedBigInteger('participant_id')->nullable();
            $t->unsignedBigInteger('to_participant_id')->nullable();
            $t->string('kind', 10); // chat, caption, reaction, system, control, signal, health
            $t->text('body')->nullable();
            $t->json('data')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['meeting_id', 'id']);
            $t->index(['meeting_id', 'kind']);
        });

        // Questions-réponses (webinaires)
        Schema::create('vis_questions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->index();
            $t->unsignedBigInteger('participant_id')->nullable();
            $t->text('body');
            $t->boolean('anonymous')->default(false);
            $t->text('answer')->nullable();
            $t->string('status', 10)->default('open'); // open, answered, live, hidden
            $t->unsignedInteger('votes')->default(0);
            $t->json('voters')->nullable();
            $t->timestamps();
        });

        // Sondages
        Schema::create('vis_polls', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->index();
            $t->string('question', 255);
            $t->json('options');
            $t->boolean('multiple')->default(false);
            $t->string('status', 10)->default('open'); // draft, open, closed
            $t->json('votes')->nullable(); // {participant_id: [index]}
            $t->timestamps();
        });

        // Enregistrements
        Schema::create('vis_recordings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->index();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('participant_id')->nullable();
            $t->string('path', 190);
            $t->string('mime', 60)->default('video/webm');
            $t->unsignedBigInteger('size')->default(0);
            $t->unsignedInteger('duration_seconds')->default(0);
            $t->unsignedInteger('chunks')->default(0);
            $t->string('status', 10)->default('recording'); // recording, ready
            $t->string('token', 48)->unique();
            $t->timestamps();
        });

        // Comptes rendus
        Schema::create('vis_reports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('meeting_id')->unique();
            $t->foreignId('company_id')->index();
            $t->longText('summary')->nullable();
            $t->json('decisions')->nullable();
            $t->json('actions')->nullable();
            $t->json('attendance')->nullable();
            $t->json('stats')->nullable();
            $t->longText('transcript')->nullable();
            $t->string('source', 10)->default('auto'); // ai, auto
            $t->string('token', 48)->unique();
            $t->unsignedInteger('todos_created')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
        });

        // Module premium : inclus dans les formules Enterprise et Sur mesure
        if (Schema::hasTable('plans')) {
            foreach (DB::table('plans')->whereIn('code', ['entreprise', 'sur_mesure'])->get() as $p) {
                $m = (array) json_decode((string) $p->modules, true);
                if (! in_array('visio', $m, true)) {
                    $m[] = 'visio';
                    DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['vis_reports', 'vis_recordings', 'vis_polls', 'vis_questions', 'vis_feed', 'vis_participants', 'vis_meetings', 'vis_usage', 'vis_provider_health', 'vis_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
