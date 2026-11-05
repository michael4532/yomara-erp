<?php

namespace App\Modules\Billetterie;

use App\Models\Company;
use App\Models\Role;

class BilletterieSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction' => ['billetterie.*'],
                'Commercial' => ['billetterie.sell', 'billetterie.scan'],
                'Caissier', 'Accueil / réception' => ['billetterie.sell', 'billetterie.scan'],
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
