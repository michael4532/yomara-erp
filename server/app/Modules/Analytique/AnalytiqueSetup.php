<?php

namespace App\Modules\Analytique;

use App\Models\Company;
use App\Models\Role;

/** Comptabilité : gestion complète ; direction et chefs de projet : consultation. Axe « Projets » si le module est actif. */
class AnalytiqueSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Comptable' => ['analytique.*'],
                'Direction', 'Chef de projet' => ['analytique.view'],
                default => [],
            };
            $add = array_diff($add, $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }

        if ($company->hasModule('projets') && class_exists(\App\Modules\Projets\Models\Project::class)) {
            $prev = app()->bound('tenant') ? app('tenant') : null;
            app()->instance('tenant', $company);
            $svc = app(AnalyticService::class);
            foreach (\App\Modules\Projets\Models\Project::all() as $p) {
                $svc->projectSection($p);
            }
            $prev ? app()->instance('tenant', $prev) : app()->forgetInstance('tenant');
        }
    }
}
