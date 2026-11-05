<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mk_lists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('mk_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable()->index();
            $table->string('phone', 40)->nullable()->index();
            $table->string('tags')->nullable();
            $table->string('source', 20)->default('manual'); // manual, import, partner, lead
            $table->uuid('partner_id')->nullable();
            $table->uuid('lead_id')->nullable();
            $table->string('token', 40)->unique();
            $table->timestamp('unsubscribed_email_at')->nullable();
            $table->timestamp('unsubscribed_sms_at')->nullable();
            $table->timestamp('unsubscribed_whatsapp_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mk_list_contact', function (Blueprint $table) {
            $table->foreignId('list_id')->index();
            $table->foreignId('contact_id')->index();
            $table->primary(['list_id', 'contact_id']);
        });

        Schema::create('mk_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('channel', 20)->default('email');
            $table->string('subject')->nullable();
            $table->string('preheader')->nullable();
            $table->json('blocks')->nullable();
            $table->longText('html')->nullable(); // HTML libre (remplace les blocs si renseigné)
            $table->text('text')->nullable();     // SMS / WhatsApp
            $table->json('style')->nullable();    // couleur principale, fond, police
            $table->timestamps();
        });

        Schema::create('mk_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->string('name');
            $table->string('kind', 20)->default('newsletter'); // newsletter, proposal, promotion, event, reminder
            $table->string('channel', 20)->default('email');
            $table->string('subject')->nullable();
            $table->string('preheader')->nullable();
            $table->json('blocks')->nullable();
            $table->longText('html')->nullable();
            $table->text('text')->nullable();
            $table->json('style')->nullable();
            $table->json('list_ids')->nullable();
            $table->json('attachments')->nullable();
            $table->string('status', 20)->default('draft'); // draft, scheduled, sending, sent, cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('stats')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('mk_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->index();
            $table->foreignId('campaign_id')->index();
            $table->foreignId('contact_id')->index();
            $table->string('channel', 20);
            $table->string('to');
            $table->string('token', 40)->unique();
            $table->string('status', 20)->default('queued'); // queued, sent, failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->unsignedInteger('opens')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedInteger('clicks')->default(0);
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['mk_messages', 'mk_campaigns', 'mk_templates', 'mk_list_contact', 'mk_contacts', 'mk_lists'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
