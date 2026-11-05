<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paramètres de la société : application Microsoft 365 / Google propre (sinon celle de la plateforme), conservation
        Schema::create('mb_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique();
            $t->string('microsoft_client_id', 80)->nullable();
            $t->text('microsoft_client_secret')->nullable();
            $t->string('microsoft_tenant', 80)->nullable();
            $t->string('google_client_id', 190)->nullable();
            $t->text('google_client_secret')->nullable();
            $t->unsignedSmallInteger('retention_days')->default(90);
            $t->timestamps();
        });

        Schema::create('mb_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->nullable()->index(); // null = boîte partagée
            $t->json('shared_with')->nullable(); // ids d'utilisateurs d'une boîte partagée
            $t->string('provider', 10); // imap, pop3, microsoft, google
            $t->string('email', 190);
            $t->string('display_name', 120)->nullable();
            $t->string('color', 9)->nullable();
            $t->json('settings')->nullable(); // imap_host, imap_port, imap_security, smtp_*, username, pop_keep
            $t->text('credentials')->nullable(); // chiffré : mot de passe ou jetons OAuth
            $t->json('state')->nullable(); // curseurs (POP3 UIDL, delta…)
            $t->string('status', 10)->default('active'); // active, error, paused
            $t->string('error', 500)->nullable();
            $t->unsignedSmallInteger('sync_days')->default(30);
            $t->boolean('calendar_sync')->default(false);
            $t->timestamp('last_sync_at')->nullable();
            $t->timestamp('next_sync_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'email', 'user_id']);
        });

        Schema::create('mb_folders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->index();
            $t->string('remote_id', 500); // chemin IMAP ou identifiant Graph
            $t->string('name', 190);
            $t->string('role', 10)->default('other'); // inbox, sent, drafts, trash, junk, archive, other
            $t->unsignedInteger('unread')->default(0);
            $t->unsignedInteger('total')->default(0);
            $t->json('state')->nullable(); // uidvalidity, last_uid, delta
            $t->boolean('sync')->default(true);
            $t->timestamps();
        });

        Schema::create('mb_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('account_id');
            $t->foreignId('folder_id');
            $t->string('remote_id', 255);
            $t->string('message_id', 255)->nullable()->index();
            $t->string('thread_key', 64)->nullable()->index();
            $t->string('in_reply_to', 255)->nullable();
            $t->text('references')->nullable();
            $t->string('subject', 500)->nullable();
            $t->string('from_name', 190)->nullable();
            $t->string('from_email', 190)->nullable()->index();
            $t->json('to')->nullable();
            $t->json('cc')->nullable();
            $t->json('bcc')->nullable();
            $t->string('reply_to', 190)->nullable();
            $t->dateTime('date')->nullable();
            $t->string('snippet', 300)->nullable();
            $t->longText('body_html')->nullable();
            $t->mediumText('body_text')->nullable();
            $t->boolean('body_loaded')->default(true);
            $t->json('attachments')->nullable();
            $t->boolean('has_attachments')->default(false);
            $t->boolean('seen')->default(false);
            $t->boolean('flagged')->default(false);
            $t->boolean('answered')->default(false);
            $t->boolean('draft')->default(false);
            $t->unsignedInteger('size')->default(0);
            $t->uuid('partner_id')->nullable()->index();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['account_id', 'folder_id', 'date']);
            $t->index(['company_id', 'updated_at']);
            $t->unique(['folder_id', 'remote_id']);
        });

        // Envois : file d'attente (envoi programmé, relances, envoi depuis un poste revenu en ligne)
        Schema::create('mb_outbox', function (Blueprint $t) {
            $t->uuid('id')->primary(); // fourni par l'application : un même envoi n'est jamais doublé
            $t->foreignId('company_id')->index();
            $t->foreignId('account_id');
            $t->foreignId('user_id');
            $t->json('payload');
            $t->char('fingerprint', 40)->nullable(); // empreinte du contenu (détection d'envoi massif)
            $t->unsignedSmallInteger('recipients')->default(1);
            $t->timestamp('send_at')->nullable();
            $t->string('status', 10)->default('queued'); // queued, sending, sent, failed, cancelled
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->string('error', 500)->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'send_at']);
            $t->index(['account_id', 'created_at']);
        });

        Schema::create('mb_signatures', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->nullable()->index(); // null = signature de la société (modèle)
            $t->foreignId('account_id')->nullable();
            $t->string('name', 120);
            $t->json('fields')->nullable(); // générateur : nom, fonction, téléphone, logo…
            $t->mediumText('html');
            $t->boolean('is_default')->default(false);
            $t->boolean('on_reply')->default(true);
            $t->timestamps();
        });

        Schema::create('mb_templates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->foreignId('user_id')->nullable();
            $t->string('name', 120);
            $t->string('subject', 300)->nullable();
            $t->mediumText('html');
            $t->timestamps();
        });

        Schema::create('mb_oauth_states', function (Blueprint $t) {
            $t->string('state', 64)->primary();
            $t->foreignId('company_id');
            $t->foreignId('user_id');
            $t->string('provider', 10);
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        // Agenda ↔ Outlook / Google
        Schema::create('ag_event_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->index();
            $t->uuid('event_id')->index();
            $t->string('external_id', 255);
            $t->string('etag', 255)->nullable();
            $t->timestamp('synced_at')->nullable(); // updated_at de l'événement Yomara au dernier échange
            $t->timestamps();
            $t->unique(['account_id', 'external_id']);
        });

        if (Schema::hasTable('roles')) {
            foreach (DB::table('roles')->whereNotNull('permissions')->get(['id', 'permissions']) as $r) {
                $p = (array) json_decode((string) $r->permissions, true);
                if (in_array('visio.use', $p, true) && ! in_array('mailbox.use', $p, true)) {
                    $p[] = 'mailbox.use';
                    DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode(array_values($p))]);
                }
            }
        }

        foreach ((Schema::hasTable('plans') ? DB::table('plans')->whereIn('code', ['business', 'professional', 'entreprise', 'sur_mesure'])->get() : []) as $p) {
            $m = (array) json_decode((string) $p->modules, true);
            if (! in_array('mailbox', $m, true)) {
                $m[] = 'mailbox';
                DB::table('plans')->where('id', $p->id)->update(['modules' => json_encode(array_values($m))]);
            }
        }
    }

    public function down(): void
    {
        foreach (['ag_event_links', 'mb_oauth_states', 'mb_templates', 'mb_signatures', 'mb_outbox', 'mb_messages', 'mb_folders', 'mb_accounts', 'mb_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
