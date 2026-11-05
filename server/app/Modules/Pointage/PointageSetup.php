<?php

namespace App\Modules\Pointage;

use App\Models\Company;
use App\Models\Role;

/** Horaire par défaut, badges des salariés existants, droits de pointage des rôles. */
class PointageSetup
{
    public function __invoke(Company $company): void
    {
        app()->instance('tenant', $company);
        app(PointageService::class)->ensureSetup($company);
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = [];
            if ($role->name !== 'Borne de pointage' && ! in_array('*', $perms, true) && ! in_array('pointage.self', $perms, true)) {
                $add[] = 'pointage.self';
            }
            if (in_array($role->name, ['RH / Paie'], true) && ! in_array('pointage.*', $perms, true)) {
                $add[] = 'pointage.*';
            }
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
        if (! Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Borne de pointage')->exists()) {
            $r = new Role(['name' => 'Borne de pointage', 'permissions' => ['pointage.kiosk']]);
            $r->company_id = $company->id;
            $r->save();
        }
    }
}
