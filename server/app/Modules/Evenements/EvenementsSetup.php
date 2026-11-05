<?php

namespace App\Modules\Evenements;

use App\Models\Company;
use App\Models\Role;

class EvenementsSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction' => ['evenements.*'],
                'Commercial' => ['evenements.view', 'evenements.sell'],
                'Comptable' => ['evenements.view'],
                default => [],
            };
            $add = array_diff($add, $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
    }
}
