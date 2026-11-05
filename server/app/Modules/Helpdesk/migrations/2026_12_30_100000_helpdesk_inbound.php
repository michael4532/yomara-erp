<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Réception des e-mails (boîte IMAP ou webhook) et des messages WhatsApp d'une société
        Schema::create('hd_mailboxes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->unique();
            $t->string('token', 48)->unique();
            $t->string('address')->nullable();
            $t->boolean('imap_enabled')->default(false);
            $t->string('imap_host')->nullable();
            $t->unsignedSmallInteger('imap_port')->default(993);
            $t->string('imap_encryption', 8)->default('ssl');
            $t->string('imap_username')->nullable();
            $t->text('imap_password_enc')->nullable();
            $t->string('imap_folder', 120)->default('INBOX');
            $t->unsignedBigInteger('last_uid')->default(0);
            $t->timestamp('last_check_at')->nullable();
            $t->string('last_error')->nullable();
            $t->boolean('whatsapp_enabled')->default(true);
            $t->unsignedInteger('received')->default(0);
            $t->timestamps();
        });
        Schema::create('hd_attachments', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('company_id')->index();
            $t->uuid('ticket_id')->index();
            $t->uuid('message_id')->nullable();
            $t->string('name');
            $t->string('mime', 120)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('path');
            $t->boolean('public')->default(true);
            $t->string('uploaded_by', 120)->nullable();
            $t->unsignedInteger('version')->default(0);
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::table('hd_messages', fn (Blueprint $t) => $t->string('external_id', 255)->nullable()->index());
        Schema::table('hd_tickets', fn (Blueprint $t) => $t->string('external_id', 255)->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('hd_messages', fn (Blueprint $t) => $t->dropColumn('external_id'));
        Schema::table('hd_tickets', fn (Blueprint $t) => $t->dropColumn('external_id'));
        Schema::dropIfExists('hd_attachments');
        Schema::dropIfExists('hd_mailboxes');
    }
};
