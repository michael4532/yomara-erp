<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double authentification : activations en cours et défis de connexion en base (et non dans le cache du serveur,
 * qui peut être vidé ou non partagé sur un hébergement mutualisé : « Activation expirée »).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('two_factor_pending')) {
            Schema::create('two_factor_pending', function (Blueprint $t) {
                $t->id();
                $t->string('token_hash', 64)->unique();
                $t->string('kind', 10);                 // setup | challenge
                $t->foreignId('user_id')->index();
                $t->text('data');                        // chiffré
                $t->unsignedSmallInteger('attempts')->default(0);
                $t->timestamp('expires_at')->index();
                $t->timestamps();
            });
        }
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'two_factor_last_step')) {
                $t->unsignedBigInteger('two_factor_last_step')->nullable(); // dernier code d'application accepté (anti-rejeu)
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('two_factor_pending');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('two_factor_last_step'));
    }
};
