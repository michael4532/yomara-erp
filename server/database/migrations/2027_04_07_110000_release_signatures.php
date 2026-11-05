<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Mises à jour signées par l'éditeur (Ed25519) : une installation n'applique qu'un paquet signé par Yomara. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('upd_releases', 'signature')) {
            Schema::table('upd_releases', fn (Blueprint $t) => $t->string('signature', 200)->nullable()->after('sha256'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('upd_releases', 'signature')) {
            Schema::table('upd_releases', fn (Blueprint $t) => $t->dropColumn('signature'));
        }
    }
};
