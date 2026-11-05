<?php

namespace App\Modules\Activite;

use App\Models\Company;
use App\Models\Role;

/** Tout le monde rédige son rapport (sauf les bornes de pointage) ; la direction et les RH voient tous les rapports. */
class ActiviteSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            if ($role->name === 'Borne de pointage') {
                continue;
            }
            $perms = $role->permissions ?? [];
            $add = array_diff(array_merge(['activite.use'], in_array($role->name, ['Direction', 'RH / Paie'], true) ? ['activite.view_all'] : []), $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
    }
}
