<?php

namespace App\Modules\Veille;

use App\Models\Company;

/** Indicateurs de l'accueil : observations récentes et écart de prix moyen avec les concurrents. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $d = app(VeilleHooks::class)->dashboard($company);

        return [
            ['key' => 'vc_obs', 'label' => 'Veille : observations (30 jours)', 'value' => $d['observations_30'], 'format' => 'number', 'icon' => 'radar'],
            ['key' => 'vc_gap', 'label' => 'Prix des concurrents / nos prix', 'value' => $d['avg_gap'] === null ? null : $d['avg_gap'], 'format' => 'percent', 'icon' => 'sell'],
        ];
    }
}
