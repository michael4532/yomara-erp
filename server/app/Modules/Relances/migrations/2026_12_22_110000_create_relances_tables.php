<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rl_reminders', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->index();
            $t->uuid('partner_id')->index();
            $t->unsignedTinyInteger('level');
            $t->string('level_name', 80);
            $t->json('documents');            // [{id, number, balance, currency, days}]
            $t->json('channels')->nullable(); // {email: "ok"|"erreur…", whatsapp: …}
            $t->string('status', 10);          // sent, failed
            $t->boolean('manual')->default(false);
            $t->foreignId('user_id')->nullable();
            $t->timestamps();
        });

        Schema::table('trade_documents', function (Blueprint $t) {
            $t->unsignedTinyInteger('dunning_level')->default(0);
            $t->timestamp('last_dunning_at')->nullable();
        });
        Schema::table('partners', fn (Blueprint $t) => $t->boolean('dunning_paused')->default(false));
    }

    public function down(): void
    {
        Schema::dropIfExists('rl_reminders');
        Schema::table('trade_documents', fn (Blueprint $t) => $t->dropColumn(['dunning_level', 'last_dunning_at']));
        Schema::table('partners', fn (Blueprint $t) => $t->dropColumn('dunning_paused'));
    }
};
