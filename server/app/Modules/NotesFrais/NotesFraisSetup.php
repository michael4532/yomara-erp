<?php

namespace App\Modules\NotesFrais;

use App\Models\Company;
use App\Models\Role;

/** Catégories de départ et droits : chaque salarié saisit ses frais, la RH / la comptabilité valide et rembourse. */
class NotesFraisSetup
{
    public function __invoke(Company $company): void
    {
        app()->instance('tenant', $company);
        app(NotesFraisService::class)->ensureDefaults();
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = [];
            // Tout le personnel (comme l'espace salarié), sauf la borne de pointage
            if ($role->name !== 'Borne de pointage' && ! in_array('*', $perms, true)) {
                $add[] = 'notes_frais.self';
            }
            if (in_array($role->name, ['Comptable', 'RH / Paie'], true)) {
                $add[] = 'notes_frais.*';
            }
            if ($role->name === 'Direction') {
                $add[] = 'notes_frais.approve';
            }
            $add = array_diff($add, $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
    }
}
