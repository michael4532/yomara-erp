<?php

namespace App\Modules\Signature;

use App\Models\Company;
use App\Models\Role;

/** Droits : la direction, les commerciaux, la RH et la comptabilité font signer. */
class SignatureSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction', 'Commercial', 'RH / Paie', 'Comptable', 'Chef de projet', 'Recruteur', 'Responsable SAV' => ['signature.*'],
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
