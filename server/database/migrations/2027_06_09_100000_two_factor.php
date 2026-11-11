<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double authentification : code d'une application (Google Authenticator, Microsoft Authenticator…) ou code reçu par e-mail,
 * plus des codes de secours. Secret et codes de secours sont chiffrés avec la clé de l'application.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'two_factor_method')) {
                $t->string('two_factor_method', 10)->nullable();          // app | email (null = désactivée)
                $t->text('two_factor_secret')->nullable();                // chiffré
                $t->text('two_factor_recovery_codes')->nullable();        // chiffré (empreintes)
                $t->timestamp('two_factor_confirmed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['two_factor_method', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']);
        });
    }
};
