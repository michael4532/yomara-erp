<?php

namespace App\Modules\Immobilisations;

use App\Models\Company;
use App\Models\Role;

/** Catégories SYSCOHADA par défaut ; Comptable gère, Direction consulte. */
class ImmobilisationsSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Comptable' => ['immobilisations.*'],
                'Direction' => ['immobilisations.view'],
                default => [],
            };
            $add = array_diff($add, $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
        $prev = app()->bound('tenant') ? app('tenant') : null;
        app()->instance('tenant', $company);
        app(AssetService::class)->setup($company);
        $prev ? app()->instance('tenant', $prev) : app()->forgetInstance('tenant');
    }
}
