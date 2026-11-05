<?php

namespace App\Modules\Planning;

use App\Models\Company;
use App\Models\Role;

class PlanningSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction', 'Chef de projet' => ['planning.*'],
                'Commercial', 'Technicien', 'Salarié', 'Équipe projet', 'RH / Paie', 'Responsable SAV' => ['planning.view'],
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
