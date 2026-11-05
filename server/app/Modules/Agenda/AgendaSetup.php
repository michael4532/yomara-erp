<?php

namespace App\Modules\Agenda;

use App\Models\Company;
use App\Models\Role;

/** Tout le personnel utilise l'agenda (sauf la borne de pointage) ; la direction gère les ressources. */
class AgendaSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            if ($role->name === 'Borne de pointage' || in_array('*', $perms, true)) {
                continue;
            }
            $add = array_diff($role->name === 'Direction' ? ['agenda.use', 'agenda.manage'] : ['agenda.use'], $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
    }
}
