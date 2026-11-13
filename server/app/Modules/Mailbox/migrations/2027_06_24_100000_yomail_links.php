<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// YoMail : suivi des clics des liens envoyés, lien de l'application sous les e-mails.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mb_link_tracks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('account_id')->nullable();
            $t->string('outbox_id', 40)->nullable();
            $t->string('token', 40)->unique();
            $t->string('message_id', 250)->nullable()->index();
            $t->string('subject', 300)->nullable();
            $t->unsignedInteger('clicks')->default(0);
            $t->timestamp('first_click_at')->nullable();
            $t->timestamp('last_click_at')->nullable();
            $t->timestamps();
        });
        Schema::create('mb_link_clicks', function (Blueprint $t) {
            $t->id();
            $t->string('token', 40)->index();
            $t->string('url', 2000);
            $t->timestamp('clicked_at')->nullable();
        });
        Schema::table('mb_settings', function (Blueprint $t) {
            $t->boolean('app_link_footer')->default(false);
            $t->boolean('track_clicks')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mb_link_clicks');
        Schema::dropIfExists('mb_link_tracks');
        Schema::table('mb_settings', fn (Blueprint $t) => $t->dropColumn(['app_link_footer', 'track_clicks']));
    }
};
