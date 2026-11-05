<?php

namespace App\Modules\Helpdesk;

use App\Models\Company;
use App\Models\Role;
use App\Modules\Helpdesk\Models\Category;
use App\Modules\Helpdesk\Models\Macro;
use App\Modules\Helpdesk\Models\SlaPolicy;

class HelpdeskSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction', 'Responsable SAV' => ['helpdesk.*'],
                'Technicien', 'Chef de projet', 'Commercial', 'Accueil / réception' => ['helpdesk.agent'],
                'Comptable' => ['helpdesk.view'],
                default => [],
            };
            $add = array_diff($add, $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }
        $bound = app()->bound('tenant') ? app('tenant') : null;
        app()->instance('tenant', $company);
        if (! SlaPolicy::exists()) {
            $p = new SlaPolicy;
            $p->forceFill(['name' => 'Standard', 'targets' => SlaPolicy::DEFAULT_TARGETS, 'business_hours' => true, 'is_default' => true])->save();
        }
        if (! Category::exists()) {
            foreach (['Question', 'Panne ou incident', 'Demande de modification', 'Facturation'] as $i => $n) {
                $c = new Category;
                $c->forceFill(['name' => $n, 'sort' => $i, 'public' => true, 'color' => ['#1F6FEB', '#D93025', '#8E44AD', '#12A150'][$i]])->save();
            }
        }
        if (! Macro::exists()) {
            foreach ([
                ['Accusé de prise en charge', "Bonjour {client},\n\nMerci pour votre message. Je prends votre demande en charge et reviens vers vous très vite.\n\n{agent}", 'open'],
                ['Besoin de précisions', "Bonjour {client},\n\nPour avancer, pouvez-vous nous préciser : \n- \n\nMerci,\n{agent}", 'pending'],
                ['Problème résolu', "Bonjour {client},\n\nLe problème est corrigé. N'hésitez pas à répondre si quelque chose ne va pas.\n\nBonne journée,\n{agent}", 'resolved'],
            ] as [$n, $b, $st]) {
                $m = new Macro;
                $m->forceFill(['name' => $n, 'body' => $b, 'set_status' => $st])->save();
            }
        }
        $bound ? app()->instance('tenant', $bound) : app()->forgetInstance('tenant');
    }
}
