<?php

namespace App\Modules\Devises;

use App\Models\Company;
use App\Models\Role;

class DevisesSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            if (in_array($role->name, ['Comptable', 'Direction'], true) && ! in_array('devises.manage', $perms, true)) {
                $role->permissions = array_values(array_merge($perms, ['devises.manage']));
                $role->save();
            }
        }
    }
}
