<?php

namespace App\Modules\Rapports;

use App\Models\Company;
use App\Models\Role;
use App\Modules\Rapports\Models\Board;
use App\Modules\Rapports\Models\Report;

/**
 * Droits par rôle et tableau de bord « Pilotage » partagé avec la direction, construit à partir des
 * modèles dont les modules sont actifs.
 */
class RapportsSetup
{
    public function __invoke(Company $company): void
    {
        foreach (Role::withoutGlobalScopes()->where('company_id', $company->id)->get() as $role) {
            $perms = $role->permissions ?? [];
            $add = match ($role->name) {
                'Direction' => ['rapports.*'],
                'Comptable', 'Commercial', 'Chef de projet', 'RH / Paie', 'Responsable de salle', 'Responsable SAV', 'Acheteur', 'Responsable de production' => ['rapports.view', 'rapports.create'],
                default => [],
            };
            $add = array_diff($add, $perms);
            if ($add) {
                $role->permissions = array_values(array_merge($perms, $add));
                $role->save();
            }
        }

        $prev = app()->bound('tenant') ? app('tenant') : null;
        app()->instance('tenant', $company);
        try {
            if (! Board::withTrashed()->exists()) {
                $this->installDefaultBoard($company);
            }
        } finally {
            $prev ? app()->instance('tenant', $prev) : app()->forgetInstance('tenant');
        }
    }

    public function installDefaultBoard(Company $company): ?Board
    {
        $engine = app(ReportEngine::class);
        $templates = Templates::all();
        $direction = Role::withoutGlobalScopes()->where('company_id', $company->id)->whereIn('name', ['Direction', 'Administrateur'])->pluck('id')->map(fn ($i) => (int) $i)->all();
        $widgets = [];
        foreach (Templates::DEFAULT_BOARD as $i => [$key, $w]) {
            $t = $templates[$key];
            $src = Sources::get($t['source']);
            if (! $company->hasModule($src['module'])) {
                continue;
            }
            $r = new Report(['name' => $t['name'], 'description' => $t['description'], 'source' => $t['source'], 'config' => $engine->normalize($t['source'], $t['config']),
                'folder' => $t['folder'], 'visibility' => $direction ? 'roles' : 'company', 'role_ids' => $direction]);
            $r->forceFill(['template' => $key])->save();
            $widgets[] = ['id' => 'w'.($i + 1), 'report_id' => $r->id, 'title' => null, 'w' => $w, 'h' => in_array($t['config']['chart'], ['kpi'], true) ? 1 : 2];
        }
        if (! $widgets) {
            return null;
        }

        return Board::create(['name' => 'Pilotage de la direction', 'description' => 'Indicateurs clés : ventes, encaissements, trésorerie, résultat.',
            'widgets' => $widgets, 'visibility' => $direction ? 'roles' : 'company', 'role_ids' => $direction, 'sort' => 0]);
    }
}
