<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Formulaire d'inscription (questions, consentement, liste marketing), avis après l'événement
        Schema::table('ev_events', function (Blueprint $t) {
            $t->json('registration')->nullable();
            $t->unsignedBigInteger('mk_list_id')->nullable();
            $t->boolean('feedback_enabled')->default(true);
            $t->timestamp('feedback_sent_at')->nullable();
        });
        // Inscription : société, fonction, réponses, consentement, provenance
        Schema::table('ev_ticket_orders', function (Blueprint $t) {
            $t->string('buyer_company', 160)->nullable();
            $t->string('buyer_job', 120)->nullable();
            $t->json('answers')->nullable();
            $t->boolean('consent')->default(false);
            $t->string('source', 60)->nullable();
        });
        // Stands : dimensions réelles
        Schema::table('ev_spaces', function (Blueprint $t) {
            $t->decimal('width_m', 6, 2)->nullable();
            $t->decimal('depth_m', 6, 2)->nullable();
        });
        // Espace exposant / sponsor : présentation publique de l'entreprise
        Schema::table('ev_bookings', function (Blueprint $t) {
            $t->json('profile')->nullable();
            $t->string('logo_path')->nullable();
            $t->boolean('profile_published')->default(false);
        });
        Schema::create('ev_feedback', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('event_id')->index();
            $t->uuid('ticket_id')->nullable()->unique();
            $t->uuid('booking_id')->nullable();
            $t->string('name', 160)->nullable();
            $t->string('email')->nullable();
            $t->unsignedTinyInteger('rating');
            $t->unsignedTinyInteger('nps')->nullable();
            $t->text('comment')->nullable();
            $t->json('answers')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ev_feedback');
        Schema::table('ev_bookings', fn (Blueprint $t) => $t->dropColumn(['profile', 'logo_path', 'profile_published']));
        Schema::table('ev_spaces', fn (Blueprint $t) => $t->dropColumn(['width_m', 'depth_m']));
        Schema::table('ev_ticket_orders', fn (Blueprint $t) => $t->dropColumn(['buyer_company', 'buyer_job', 'answers', 'consent', 'source']));
        Schema::table('ev_events', fn (Blueprint $t) => $t->dropColumn(['registration', 'mk_list_id', 'feedback_enabled', 'feedback_sent_at']));
    }
};
