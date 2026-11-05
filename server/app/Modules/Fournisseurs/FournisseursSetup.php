<?php

namespace App\Modules\Fournisseurs;

use App\Models\Company;
use App\Models\Role;

class FournisseursSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction', 'Acheteur' => ['portail_fournisseurs.*'],
                'Comptable', 'Magasinier' => ['portail_fournisseurs.view'],
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
