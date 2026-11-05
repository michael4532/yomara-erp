<?php

namespace App\Modules\Pilotage;

use App\Models\Company;

/** Indicateurs de l'accueil : EBE estimé du mois. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $d = app(PilotageService::class)->dashboard($company);

        return [
            ['key' => 'pl_ebitda', 'label' => 'EBE estimé du mois', 'value' => $d['ebitda_month'], 'format' => 'money', 'icon' => 'query_stats', 'permission' => 'pilotage.view'],
        ];
    }
}
