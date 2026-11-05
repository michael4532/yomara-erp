<?php

namespace App\Modules\Acquisition;

use App\Models\Company;

/** Indicateurs de l'accueil : prospects reçus et coût par prospect sur 30 jours. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $r = app(Roi::class)->report($company, now()->subDays(29)->toDateString(), now()->toDateString());

        return [
            ['key' => 'ac_leads', 'label' => 'Prospects des publicités (30 j)', 'value' => $r['leads'], 'format' => 'number', 'icon' => 'person_add'],
            ['key' => 'ac_cpl', 'label' => 'Coût par prospect (30 j)', 'value' => $r['cpl'], 'format' => 'money', 'icon' => 'ads_click'],
        ];
    }
}
