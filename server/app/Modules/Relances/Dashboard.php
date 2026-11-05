<?php

namespace App\Modules\Relances;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $a = app(DunningService::class)->aging($company);

        return [
            ['key' => 'rl_over60', 'label' => 'Créances échues depuis plus de 60 jours', 'value' => round($a['totals']['d90'] + $a['totals']['over90'], 2), 'format' => 'money', 'icon' => 'warning', 'permission' => 'relances.view|relances.manage', 'alert' => ($a['totals']['d90'] + $a['totals']['over90']) > 0],
        ];
    }
}
