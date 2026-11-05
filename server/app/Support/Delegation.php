<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Délégation des droits : un utilisateur ne peut donner (rôle, droits individuels) ni modifier
 * que ce qu'il possède lui-même. Seul un administrateur complet (droit « * ») peut tout attribuer.
 */
class Delegation
{
    public static function full(User $u): bool
    {
        return $u->is_super_admin || in_array('*', (array) ($u->role?->permissions ?? []), true);
    }

    public static function canGrant(User $caller, string $perm): bool
    {
        if (self::full($caller)) {
            return true;
        }
        if ($perm === '*') {
            return false;
        }
        if (str_contains($perm, '*') || str_contains($perm, '?')) {
            // Droit générique (« ventes.* ») : il faut posséder chacun des droits qu'il couvre
            $all = array_filter(array_keys(app(ModuleRegistry::class)->permissions()), fn ($k) => fnmatch($perm, $k));

            return $all !== [] && collect($all)->every(fn ($p) => $caller->hasPermission($p));
        }

        return $caller->hasPermission($perm);
    }

    public static function covers(User $caller, array $perms): bool
    {
        return collect($perms)->every(fn ($p) => self::canGrant($caller, (string) $p));
    }

    /** Le compte visé n'a pas plus de droits que celui qui le modifie. */
    public static function within(User $caller, User $target): bool
    {
        if (self::full($caller)) {
            return ! $target->is_super_admin || $caller->is_super_admin;
        }

        return ! self::full($target) && self::covers($caller, $target->permissions());
    }

    public static function roleWithin(User $caller, Role $role): bool
    {
        return self::covers($caller, (array) $role->permissions);
    }
}
