<?php

namespace App\Modules\Feuilles;

use App\Models\Company;
use App\Models\Role;

class FeuillesSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction', 'Chef de projet' => ['feuilles_temps.*'],
                'Responsable SAV' => ['feuilles_temps.submit', 'feuilles_temps.approve'],
                'Technicien', 'Salarié', 'Équipe projet', 'Commercial', 'Comptable', 'RH / Paie', 'Accueil / réception' => ['feuilles_temps.submit'],
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
