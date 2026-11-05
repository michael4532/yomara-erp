<?php

return [
    'key' => 'feuilles_temps',
    'name' => 'Feuilles de temps',
    'description' => 'Chaque semaine, chacun envoie sa feuille de temps (projets, forfaits, tickets) ; le responsable la valide ou la renvoie avec un commentaire. Une semaine envoyée est verrouillée et seul le temps validé est facturé.',
    'category' => 'Opérations',
    'icon' => 'fact_check',
    'depends' => ['core'],
    'sort' => 62,
    'setup' => \App\Modules\Feuilles\FeuillesSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Feuilles\Dashboard::class,
    'permissions' => [
        'feuilles_temps.submit' => 'Envoyer sa feuille de temps',
        'feuilles_temps.approve' => 'Valider les feuilles de temps de l\'équipe',
    ],
    'sync' => [
        'tt_weeks' => \App\Modules\Feuilles\Models\Week::class,
    ],
];
