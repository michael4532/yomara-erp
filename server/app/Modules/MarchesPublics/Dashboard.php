<?php

namespace App\Modules\MarchesPublics;

use App\Models\Company;

/** Indicateurs de l'accueil : dépôts de la semaine et carnet de commandes. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $d = app(MarchesHooks::class)->dashboard($company);

        return [
            ['key' => 'mp_due', 'label' => 'Dépôts d\'offres (7 jours)', 'value' => $d['due_week'], 'format' => 'number', 'icon' => 'gavel'],
            ['key' => 'mp_backlog', 'label' => 'Marchés : reste à exécuter', 'value' => $d['backlog'], 'format' => 'money', 'icon' => 'handshake'],
        ];
    }
}
