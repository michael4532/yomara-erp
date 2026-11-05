<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\InsightService;
use App\Support\BranchScope;

/** Tableau de bord analytique (direction) : réservé aux utilisateurs qui voient les ventes ou les rapports. */
class InsightsController extends Controller
{
    public function __invoke(InsightService $svc)
    {
        $c = app('tenant');
        $u = request()->user();
        abort_unless($c->hasModule('ventes') && ($u->hasPermission('ventes.view') || $u->hasPermission('rapports.view')), 403, 'Tableau de bord analytique non disponible.');
        $branch = request()->query('branch_id');
        if ($branch && BranchScope::canUse($branch)) {
            return response()->json(BranchScope::withFilter($branch, fn () => $svc->build($c, $u)));
        }

        return response()->json($svc->build($c, $u));
    }
}
