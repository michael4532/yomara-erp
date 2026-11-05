<?php

namespace App\Modules\Maintenance;

use App\Models\Company;

/** Indicateurs de l'accueil : équipements en panne et interventions ouvertes. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $d = app(MaintenanceHooks::class)->dashboard($company);

        return [
            ['key' => 'mt_down', 'label' => 'Équipements en panne', 'value' => $d['down'], 'format' => 'number', 'icon' => 'build'],
            ['key' => 'mt_open', 'label' => 'Interventions ouvertes', 'value' => $d['open'], 'format' => 'number', 'icon' => 'construction'],
        ];
    }
}
