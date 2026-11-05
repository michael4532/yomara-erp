<?php

namespace App\Modules\Fne;

use App\Models\Company;
use App\Models\Role;

/** La direction et la comptabilité paramètrent la FNE ; les commerciaux consultent. */
class FneSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction', 'Comptable' => ['fne.*'],
                'Commercial', 'Caissier' => ['fne.view'],
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
