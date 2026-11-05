<?php

namespace App\Modules\Location;

use App\Models\Company;

/** Indicateurs de l'accueil : matériel loué et retours en retard. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $d = app(LocationHooks::class)->dashboard($company);

        return [
            ['key' => 'lo_util', 'label' => 'Matériel loué (taux d\'utilisation)', 'value' => $d['utilization'], 'format' => 'percent', 'icon' => 'key'],
            ['key' => 'lo_late', 'label' => 'Locations : retours en retard', 'value' => $d['late'], 'format' => 'number', 'icon' => 'warning'],
        ];
    }
}
