<?php

namespace App\Modules\Prestations;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $s = app(PrestationService::class)->stats();
        $p = 'prestations.view|prestations.log|prestations.manage';

        return [
            ['key' => 'pr_low', 'label' => 'Forfaits presque consommés', 'value' => $s['low_packs'], 'format' => 'number', 'icon' => 'work_history', 'permission' => $p],
            ['key' => 'pr_awaiting', 'label' => 'Livrables en attente du client', 'value' => $s['awaiting'], 'format' => 'number', 'icon' => 'rate_review', 'permission' => $p],
        ];
    }
}
