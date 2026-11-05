<?php

namespace App\Modules\Pdf;

use App\Models\Company;
use App\Models\Role;

/** Outils PDF : utiles à tout le monde, droit donné à tous les rôles existants. */
class PdfSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            if (! in_array('pdf.use', $perms, true) && ! in_array('*', $perms, true)) {
                $role->permissions = array_values([...$perms, 'pdf.use']);
                $role->save();
            }
        }
    }
}
