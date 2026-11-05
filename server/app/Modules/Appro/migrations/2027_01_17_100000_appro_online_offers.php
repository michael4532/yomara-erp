<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ap_comparisons', function (Blueprint $t) {
            $t->string('source_type', 20)->nullable();   // request, rfq, fund, tender, spec
            $t->string('source_id', 40)->nullable();
            $t->string('source_label', 200)->nullable();
            $t->text('instructions')->nullable();       // consignes affichées aux fournisseurs
            $t->index(['company_id', 'source_type', 'source_id']);
        });
        // Fournisseurs consultés : lien personnel pour répondre en ligne
        Schema::create('ap_invites', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('comparison_id')->index();
            $t->uuid('partner_id')->nullable();
            $t->string('name', 160);
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('offer_key', 40);
            $t->string('token', 48)->unique();
            $t->string('status', 10)->default('sent'); // sent, opened, answered, declined
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('opened_at')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->text('decline_reason')->nullable();
            $t->string('file_path')->nullable();
            $t->string('file_name')->nullable();
            $t->unsignedSmallInteger('revisions')->default(0);
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
            $t->unique(['comparison_id', 'partner_id']);
        });
        // Questions et réponses avec les fournisseurs sur leur offre
        Schema::create('ap_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->unsignedBigInteger('comparison_id')->index();
            $t->unsignedBigInteger('invite_id')->nullable()->index();
            $t->string('direction', 3); // out : question de l'acheteur, in : message du fournisseur
            $t->string('channel', 10)->default('portal'); // portal, email, phone, whatsapp
            $t->text('body');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('author', 160)->nullable();
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_messages');
        Schema::dropIfExists('ap_invites');
        Schema::table('ap_comparisons', function (Blueprint $t) {
            $t->dropIndex(['company_id', 'source_type', 'source_id']);
            $t->dropColumn(['source_type', 'source_id', 'source_label', 'instructions']);
        });
    }
};
