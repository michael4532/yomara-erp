<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique();
            $t->string('webhook_token', 48)->unique();
            $t->string('verify_token', 48);
            $t->string('display_phone', 30)->nullable(); // numéro WhatsApp de la société (liens wa.me)
            $t->boolean('auto_assign')->default(true); // répartition à tour de rôle
            $t->text('welcome_message')->nullable();
            $t->text('away_message')->nullable();
            $t->json('hours')->nullable(); // {"1":["08:00","18:00"], …, "7": null}
            $t->unsignedInteger('rr_cursor')->default(0);
            $t->timestamps();
        });

        Schema::create('wa_conversations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('phone', 20); // chiffres E.164 sans « + »
            $t->string('name', 190)->nullable();
            $t->uuid('partner_id')->nullable()->index();
            $t->foreignId('assigned_to')->nullable()->index();
            $t->string('status', 10)->default('open'); // open, pending, closed
            $t->json('labels')->nullable();
            $t->unsignedInteger('unread')->default(0);
            $t->string('last_preview', 300)->nullable();
            $t->string('last_direction', 3)->nullable();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamp('last_inbound_at')->nullable(); // fenêtre de 24 h de Meta
            $t->timestamp('welcome_sent_at')->nullable();
            $t->timestamp('away_sent_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'phone']);
            $t->index(['company_id', 'last_message_at']);
        });

        Schema::create('wa_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('conversation_id')->index();
            $t->string('direction', 3); // in, out
            $t->string('type', 12)->default('text'); // text, image, document, audio, video, location, sticker, template, contacts, other
            $t->text('body')->nullable();
            $t->string('media_path', 300)->nullable();
            $t->string('media_mime', 120)->nullable();
            $t->string('file_name', 190)->nullable();
            $t->string('provider_id', 190)->nullable()->index();
            $t->string('status', 10)->default('sent'); // queued, sent, delivered, read, failed, received
            $t->string('error', 500)->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('template', 120)->nullable();
            $t->boolean('auto')->default(false);
            $t->timestamps();
        });

        Schema::create('wa_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('name', 120);
            $t->string('language', 10)->default('fr');
            $t->string('category', 20)->nullable(); // MARKETING, UTILITY, AUTHENTICATION
            $t->string('status', 20)->default('APPROVED'); // APPROVED, PENDING, REJECTED
            $t->text('body')->nullable();
            $t->unsignedTinyInteger('params')->default(0);
            $t->json('components')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'name', 'language']);
        });

        Schema::create('wa_quick_replies', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->string('shortcut', 40);
            $t->text('body');
            $t->timestamps();
        });

        // Rôles existants : ceux qui envoient déjà des messages reçoivent la messagerie WhatsApp
        if (Schema::hasTable('roles')) {
            foreach (DB::table('roles')->whereNotNull('permissions')->get(['id', 'permissions']) as $r) {
                $p = (array) json_decode((string) $r->permissions, true);
                if (array_intersect($p, ['crm.*', 'ventes.*', 'helpdesk.*', 'mailbox.use']) && ! in_array('whatsapp.use', $p, true)) {
                    $p[] = 'whatsapp.use';
                    DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode(array_values($p))]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (['wa_quick_replies', 'wa_templates', 'wa_messages', 'wa_conversations', 'wa_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
