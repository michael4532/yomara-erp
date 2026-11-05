<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Préférences d'interface de chaque utilisateur (favoris, groupes du menu ouverts, menu réduit…) gardées sur le serveur :
// elles survivent à la déconnexion et suivent la personne sur le web, l'ordinateur et le téléphone.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'ui_prefs')) {
            Schema::table('users', fn (Blueprint $t) => $t->json('ui_prefs')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('ui_prefs'));
    }
};
