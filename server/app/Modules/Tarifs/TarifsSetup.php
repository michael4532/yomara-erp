<?php

namespace App\Modules\Tarifs;

use App\Models\Company;
use App\Models\Role;

class TarifsSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('name', ['Direction', 'Responsable de salle'])->get() as $role) {
            $perms = $role->permissions ?? [];
            if (! in_array('tarifs.manage', $perms, true)) {
                $role->permissions = array_values(array_merge($perms, ['tarifs.manage']));
                $role->save();
            }
        }
    }
}
