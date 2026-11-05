<?php

namespace App\Modules\Contenus;

use App\Models\Company;

/** Indicateurs de l'accueil : publications de la semaine et à valider. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $d = app(ContenusHooks::class)->dashboard($company);

        return [
            ['key' => 'contenus_week', 'label' => 'Publications cette semaine', 'value' => $d['week'], 'format' => 'number', 'icon' => 'calendar_month'],
            ['key' => 'contenus_review', 'label' => 'Publications à valider', 'value' => $d['review'], 'format' => 'number', 'icon' => 'fact_check'],
        ];
    }
}
