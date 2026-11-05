<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Role;

/** Droits par défaut d'un module : ajoute des permissions aux rôles existants et crée le rôle du service s'il manque. */
class ServiceRoles
{
    /**
     * @param array<string, string[]> $grants  rôle => permissions à ajouter
     * @param array<string, string[]> $create  rôle du service à créer s'il n'existe pas => permissions
     */
    public static function apply(Company $company, array $grants, array $create = []): void
    {
        foreach ($create as $name => $perms) {
            Role::withoutGlobalScopes()->firstOrCreate(['company_id' => $company->id, 'name' => $name], ['permissions' => $perms, 'is_system' => false]);
        }
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = array_diff($grants[$role->name] ?? [], $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
    }
}
