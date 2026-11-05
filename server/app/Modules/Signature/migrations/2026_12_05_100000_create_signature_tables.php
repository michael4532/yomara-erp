<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sg_requests', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->string('number', 40)->nullable();
            $t->string('title');
            $t->text('message')->nullable();
            $t->string('status', 12)->default('draft'); // draft, pending, completed, declined, cancelled, expired
            $t->boolean('sequential')->default(false);
            $t->date('expires_at')->nullable();
            $t->string('source_type', 30)->nullable(); // rh_document, sales_document
            $t->uuid('source_id')->nullable()->index();
            $t->string('original_path');
            $t->string('original_name');
            $t->char('original_sha256', 64);
            $t->unsignedSmallInteger('pages')->nullable();
            $t->string('signed_path')->nullable();
            $t->char('signed_sha256', 64)->nullable()->index();
            $t->boolean('stamped')->default(false); // pages d'origine reprises (sinon original joint au PDF)
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('created_by')->nullable();
            $t->unsignedTinyInteger('reminders')->default(0);
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'status']);
        });

        Schema::create('sg_signers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('request_id')->index();
            $t->unsignedTinyInteger('position')->default(1);
            $t->string('name');
            $t->string('label')->nullable(); // qualité : « Le Salarié », « Pour le client »…
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->foreignId('user_id')->nullable(); // signataire interne : signe dans l'application
            $t->char('token_hash', 64)->unique();
            $t->text('token'); // chiffré (pour renvoyer le lien)
            $t->string('status', 10)->default('waiting'); // waiting, pending, viewed, signed, declined
            $t->string('otp_hash')->nullable();
            $t->timestamp('otp_expires_at')->nullable();
            $t->unsignedTinyInteger('otp_attempts')->default(0);
            $t->string('otp_channel', 10)->nullable();
            $t->timestamp('otp_sent_at')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('verified_by', 20)->nullable(); // sms, whatsapp, email, account, link
            $t->timestamp('notified_at')->nullable();
            $t->timestamp('viewed_at')->nullable();
            $t->timestamp('signed_at')->nullable();
            $t->timestamp('declined_at')->nullable();
            $t->string('decline_reason', 500)->nullable();
            $t->string('signature_path')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->timestamps();
        });

        Schema::create('sg_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('request_id')->index();
            $t->foreignId('signer_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('type', 30);
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->json('data')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sg_events');
        Schema::dropIfExists('sg_signers');
        Schema::dropIfExists('sg_requests');
    }
};
