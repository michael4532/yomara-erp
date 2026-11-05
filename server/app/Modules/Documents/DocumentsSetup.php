<?php

namespace App\Modules\Documents;

use App\Models\Company;
use App\Models\Role;

/** Droits : la direction administre, les services consultent et déposent. */
class DocumentsSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction' => ['documents.*'],
                'Comptable', 'RH / Paie', 'Commercial', 'Chef de projet', 'Acheteur', 'Responsable SAV', 'Recruteur', 'Logisticien' => ['documents.view', 'documents.manage'],
                'Magasinier', 'Technicien' => ['documents.view'],
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
