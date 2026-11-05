<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lk_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('kind', 10)->default('short'); // short (lien court), smart (redirection selon l'appareil / le pays), page (page de plateformes)
            $t->string('slug', 64)->unique();
            $t->string('title')->nullable();
            $t->string('target_url', 2000)->nullable();
            $t->json('rules')->nullable();   // { devices: {ios, android, desktop}, countries: {CI: url} }
            $t->json('page')->nullable();    // { subtitle, cover_url, color, buttons: [{label, url, platform}] }
            $t->json('utm')->nullable();
            $t->string('password')->nullable();
            $t->boolean('cloak')->default(false);
            $t->timestamp('expires_at')->nullable();
            $t->unsignedInteger('max_clicks')->nullable();
            $t->json('tags')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('clicks_count')->default(0);
            $t->unsignedBigInteger('unique_count')->default(0);
            $t->timestamp('last_click_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'created_at']);
        });

        Schema::create('lk_clicks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('link_id')->constrained('lk_links')->cascadeOnDelete();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('source', 8)->default('link'); // link, qr
            $t->unsignedTinyInteger('button')->nullable(); // bouton de la page smartlink
            $t->char('visitor', 40);
            $t->boolean('is_unique')->default(false);
            $t->char('country', 2)->nullable();
            $t->string('device', 10)->nullable(); // mobile, tablet, desktop
            $t->string('os', 20)->nullable();
            $t->string('browser', 20)->nullable();
            $t->string('referrer', 120)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['link_id', 'created_at']);
            $t->index(['link_id', 'visitor']);
        });

        Schema::create('lk_qrs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name');
            $t->string('type', 12); // url, text, whatsapp, wifi, vcard, email, sms, phone, geo
            $t->json('payload');
            $t->text('data');       // contenu encodé dans le QR
            $t->json('style')->nullable(); // couleurs, forme des points, logo
            $t->foreignId('link_id')->nullable()->constrained('lk_links')->nullOnDelete(); // QR dynamique : passe par un lien suivi
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lk_qrs');
        Schema::dropIfExists('lk_clicks');
        Schema::dropIfExists('lk_links');
    }
};
