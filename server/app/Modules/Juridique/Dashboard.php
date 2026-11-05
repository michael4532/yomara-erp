<?php

namespace App\Modules\Juridique;

use App\Models\Company;

/** Indicateurs de l'accueil : contrats à renouveler et audiences de la semaine. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $d = app(JuridiqueHooks::class)->dashboard($company);

        return [
            ['key' => 'jur_alerts', 'label' => 'Contrats à renouveler ou résilier', 'value' => $d['alerts'], 'format' => 'number', 'icon' => 'gavel'],
            ['key' => 'jur_deadlines', 'label' => 'Audiences et échéances (7 jours)', 'value' => $d['deadlines_week'], 'format' => 'number', 'icon' => 'event'],
        ];
    }
}
