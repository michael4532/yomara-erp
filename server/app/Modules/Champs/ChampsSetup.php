<?php

namespace App\Modules\Champs;

use App\Models\Company;
use App\Models\Role;

class ChampsSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Direction')->get() as $role) {
            $perms = $role->permissions ?? [];
            if (! in_array('champs.manage', $perms, true)) {
                $role->permissions = array_values(array_merge($perms, ['champs.manage']));
                $role->save();
            }
        }
    }
}
