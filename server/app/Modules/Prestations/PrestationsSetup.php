<?php

namespace App\Modules\Prestations;

use App\Models\Company;
use App\Models\Role;

class PrestationsSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction', 'Chef de projet' => ['prestations.*'],
                'Commercial' => ['prestations.manage'],
                'Technicien', 'Salarié', 'Équipe projet' => ['prestations.log'],
                'Comptable' => ['prestations.view'],
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
