<?php

namespace App\Modules\Feuilles;

use App\Models\Company;
use App\Modules\Feuilles\Models\Week;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        return [
            ['key' => 'tt_pending', 'label' => 'Feuilles de temps à valider', 'value' => Week::where('status', 'submitted')->count(), 'format' => 'number', 'icon' => 'fact_check', 'permission' => 'feuilles_temps.approve'],
        ];
    }
}
