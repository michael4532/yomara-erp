<?php

namespace App\Modules\Rh;

use App\Models\Company;
use App\Modules\Rh\Models\Employee;
use App\Modules\Rh\Models\Leave;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $today = now()->toDateString();
        $team = 'rh.view|rh.manage';
        $tiles = [
            ['key' => 'rh_headcount', 'label' => 'Effectif', 'value' => Employee::where('active', true)->count(), 'format' => 'number', 'icon' => 'badge', 'permission' => $team],
            ['key' => 'rh_on_leave', 'label' => 'Absents aujourd\'hui', 'value' => Leave::where('status', 'approved')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->count(), 'format' => 'number', 'icon' => 'beach_access', 'permission' => $team],
            ['key' => 'rh_requests', 'label' => 'Demandes de congé', 'value' => Leave::where('status', 'requested')->count(), 'format' => 'number', 'icon' => 'event_note', 'permission' => $team],
        ];
        // Espace salarié : son propre solde de congés
        $me = auth()->user() ? Employee::where('user_id', auth()->id())->first() : null;
        if ($me) {
            $tiles[] = ['key' => 'rh_my_leave', 'label' => 'Mes congés disponibles (jours)', 'value' => RhController::balances(collect([$me]))[$me->id] ?? 0, 'format' => 'number', 'icon' => 'beach_access', 'permission' => 'rh.self|rh.view|rh.manage'];
        }

        return $tiles;
    }
}
