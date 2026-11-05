<?php

namespace App\Modules\Appro;

use App\Models\Company;
use App\Models\Role;

class ApproSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Acheteur', 'Magasinier' => ['appro.manage', 'appro.view'],
                'Comptable', 'Direction', 'Responsable SAV', 'Équipe projet' => ['appro.view'],
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
