<?php

namespace App\Modules\Rh;

use App\Models\Company;
use App\Models\Role;

/**
 * Espace salarié : chaque rôle existant reçoit « rh.self » (le salarié consulte sa fiche et ses bulletins,
 * demande ses congés) et le rôle « Salarié » est créé pour le personnel sans autre accès.
 */
class RhSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            if ($role->name !== 'Borne de pointage' && ! in_array('*', $perms, true) && ! in_array('rh.self', $perms, true) && ! in_array('rh.*', $perms, true)) {
                $role->permissions = array_values(array_merge($perms, ['rh.self']));
                $role->save();
            }
        }
        // Modèles de documents RH (v2)
        app()->instance('tenant', $company);
        if (! Models\DocTemplate::withTrashed()->exists()) {
            foreach (DocTemplates::defaults() as [$type, $name, $self, $body]) {
                $t = new Models\DocTemplate(['type' => $type, 'name' => $name, 'body' => $body, 'self_service' => $self]);
                $t->id = (string) \Illuminate\Support\Str::uuid();
                $t->save();
            }
        }
        $settings = $company->settings ?? [];
        if (empty($settings['rh_signatory'])) {
            $settings['rh_signatory'] = \App\Models\User::where('company_id', $company->id)->orderBy('id')->value('name');
            $settings['rh_signatory_title'] ??= 'Le Gérant';
            $company->forceFill(['settings' => $settings])->saveQuietly();
        }
        if (! Role::withoutGlobalScopes()->where('company_id', $company->id)->where('name', 'Salarié')->exists()) {
            $r = new Role(['name' => 'Salarié', 'permissions' => ['rh.self', 'achats.request']]);
            $r->company_id = $company->id;
            $r->save();
        }
    }
}
