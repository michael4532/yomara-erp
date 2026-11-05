<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Jetons Firebase des téléphones (notifications push), un par appareil et par utilisateur. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('push_tokens')) {
            return;
        }
        Schema::create('push_tokens', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('company_id')->nullable()->index();
            $t->char('token_hash', 64)->unique();
            $t->text('token');
            $t->string('platform', 15)->default('android');
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tokens');
    }
};
