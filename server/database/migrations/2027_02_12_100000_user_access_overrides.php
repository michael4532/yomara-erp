<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'permission_grants')) {
                $t->json('permission_grants')->nullable(); // droits ajoutés à ceux du rôle, pour cet utilisateur
                $t->json('permission_denies')->nullable(); // droits retirés (ex. supprimer une facture)
                $t->json('modules_denied')->nullable(); // modules de la société masqués pour cet utilisateur
            }
        });
        // Nouveaux droits distincts : supprimer et valider. Les rôles qui avaient le droit de gérer les gardent.
        $map = ['ventes.manage' => ['ventes.delete', 'ventes.validate'], 'achats.manage' => ['achats.delete'], 'core.contacts.manage' => ['core.contacts.delete'], 'core.products.manage' => ['core.products.delete']];
        if (Schema::hasTable('roles')) {
            foreach (DB::table('roles')->whereNotNull('permissions')->get(['id', 'permissions']) as $r) {
                $p = (array) json_decode((string) $r->permissions, true);
                $add = [];
                foreach ($map as $manage => $extra) {
                    if (in_array($manage, $p, true)) {
                        $add = array_merge($add, $extra);
                    }
                }
                if ($add && array_diff($add, $p)) {
                    DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode(array_values(array_unique(array_merge($p, $add))))]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['permission_grants', 'permission_denies', 'modules_denied']));
    }
};
