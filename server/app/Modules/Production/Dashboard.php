<?php

namespace App\Modules\Production;

use App\Models\Company;
use App\Modules\Production\Models\ProductionOrder;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $tz = $company->settings['timezone'] ?? config('erp.defaults.timezone', 'UTC');

        return [
            ['key' => 'mrp_running', 'label' => 'Ordres de fabrication en cours', 'value' => ProductionOrder::whereIn('status', ['confirmed', 'in_progress'])->count(), 'format' => 'number', 'icon' => 'precision_manufacturing'],
            ['key' => 'mrp_late', 'label' => 'Fabrications en retard', 'value' => ProductionOrder::whereIn('status', ['confirmed', 'in_progress'])->where('planned_date', '<', now($tz)->toDateString())->count(), 'format' => 'number', 'icon' => 'schedule', 'permission' => 'production.manage'],
        ];
    }
}
