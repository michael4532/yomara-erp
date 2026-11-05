<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Canaux d'envoi d'une société : e-mail (son SMTP ou le serveur de l'ERP), SMS, WhatsApp
        Schema::create('messaging_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('channel', 20); // email, sms, whatsapp
            $table->string('provider', 30); // smtp, platform, orange, twilio, infobip, africastalking, vonage, http, meta, ultramsg, greenapi
            $table->text('settings')->nullable(); // chiffré
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'channel']);
        });

        // Journal de tous les envois (documents, relances, campagnes)
        Schema::create('messaging_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('user_id')->nullable();
            $table->string('channel', 20);
            $table->string('provider', 30);
            $table->string('to');
            $table->string('subject')->nullable();
            $table->string('status', 20); // sent, failed
            $table->string('provider_id')->nullable();
            $table->text('error')->nullable();
            $table->string('context', 50)->nullable(); // campaign:12, document:uuid, test…
            $table->timestamps();
            $table->index(['company_id', 'channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_log');
        Schema::dropIfExists('messaging_channels');
    }
};
