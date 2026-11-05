<?php

namespace App\Modules\Recrutement;

use App\Models\Company;
use App\Models\Role;

/** Droits : la RH recrute, la direction consulte, le rôle « Recruteur » et les intervenants d'entretien. */
class RecrutementSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'RH / Paie' => ['recrutement.*'],
                'Direction', 'Chef de projet', 'Responsable SAV', 'Responsable de production', 'Responsable de salle', 'Logisticien' => ['recrutement.interview'],
                default => [],
            };
            $add = array_diff($add, $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
        if (! Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Recruteur')->exists()) {
            $r = new Role(['name' => 'Recruteur', 'permissions' => ['recrutement.*', 'rh.self', 'achats.request']]);
            $r->company_id = $company->id;
            $r->save();
        }
    }
}
