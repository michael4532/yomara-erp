<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ModuleRegistry;

/**
 * Indicateurs du tableau de bord : chaque module activé fournit les siens
 * via la clé 'dashboard' de son manifeste (classe invocable).
 */
class DashboardController extends Controller
{
    public function __invoke(ModuleRegistry $registry)
    {
        // Multi-agences : indicateurs d'une agence (?branch_id=…), dans la limite des agences de l'utilisateur
        $branch = request()->query('branch_id');
        if ($branch && \App\Support\BranchScope::canUse($branch)) {
            return \App\Support\BranchScope::withFilter($branch, fn () => $this->widgets($registry));
        }

        return $this->widgets($registry);
    }

    protected function widgets(ModuleRegistry $registry)
    {
        $company = app('tenant');
        $widgets = [];
        $user = request()->user();

        foreach ($company->enabledModules() as $key) {
            $manifest = $registry->manifest($key);
            $provider = $manifest['dashboard'] ?? null;
            // Indicateurs d'un module visibles seulement si l'utilisateur a au moins un droit sur ce module
            $perms = array_keys($manifest['permissions'] ?? []);
            if ($perms && $user && ! collect($perms)->contains(fn ($p) => $user->hasPermission($p))) {
                continue;
            }
            if ($provider && class_exists($provider)) {
                foreach (app($provider)($company) as $w) {
                    if (isset($w['permission']) && $user && ! $user->hasPermission($w['permission'])) {
                        continue;
                    }
                    $widgets[] = $w + ['module' => $key];
                }
            }
        }

        return ['data' => $widgets, 'currency' => $company->currency];
    }
}
