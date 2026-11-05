<?php

namespace App\Modules\Commercial;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

/** Indicateurs de l'accueil : visites du jour et comptes rendus en attente. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $v = DB::table('cm_visits')->where('company_id', $company->id);

        return [
            ['key' => 'cm_today', 'label' => 'Visites commerciales du jour', 'value' => (clone $v)->whereBetween('planned_at', [now()->startOfDay(), now()->endOfDay()])->count(), 'format' => 'number', 'icon' => 'storefront'],
            ['key' => 'cm_reports', 'label' => 'Comptes rendus de visite à faire', 'value' => (clone $v)->where('status', 'planned')->where('planned_at', '<', now()->subHours(2))->count(), 'format' => 'number', 'icon' => 'edit_note'],
        ];
    }
}
