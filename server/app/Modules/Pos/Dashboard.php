<?php

namespace App\Modules\Pos;

use App\Models\Company;
use App\Modules\Pos\Models\PosSession;
use App\Modules\Ventes\Models\SalesDocument;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $today = SalesDocument::where('type', 'receipt')->where('status', 'validated')->whereDate('date', now()->toDateString());

        return [
            ['key' => 'pos_today', 'label' => 'Ventes caisse du jour', 'value' => round((float) (clone $today)->sum('total'), 2), 'format' => 'money', 'icon' => 'point_of_sale'],
            ['key' => 'pos_tickets_today', 'label' => 'Tickets du jour', 'value' => (clone $today)->count(), 'format' => 'number', 'icon' => 'receipt'],
            ['key' => 'pos_open_sessions', 'label' => 'Caisses ouvertes', 'value' => PosSession::where('status', 'open')->count(), 'format' => 'number', 'icon' => 'point_of_sale'],
        ];
    }
}
