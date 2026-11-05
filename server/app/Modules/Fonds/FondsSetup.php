<?php

namespace App\Modules\Fonds;

use App\Models\Company;
use App\Models\Role;

/** Droits par défaut : chacun demande pour son service, la direction décide, la comptabilité met à disposition. */
class FondsSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction' => ['fonds.approve', 'fonds.view', 'fonds.request'],
                'Comptable' => ['fonds.provide', 'fonds.view', 'fonds.request'],
                'Acheteur', 'Magasinier' => ['fonds.provide', 'fonds.request'],
                'Administrateur', 'Lecture seule', 'Livreur', 'Caissier', 'Serveur', 'Praticien' => [],
                default => ['fonds.request'],
            };
            $add = array_diff($add, $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
    }
}
