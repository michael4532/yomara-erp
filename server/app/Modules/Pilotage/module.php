<?php

return [
    'key' => 'pilotage',
    'name' => 'Pilotage & prévisions',
    'description' => 'Le tableau de bord du dirigeant, sans être comptable : soldes intermédiaires de gestion estimés chaque mois à partir des ventes, achats, dépenses et salaires (marge brute, valeur ajoutée, EBE, résultat), comparaison avec l\'année précédente, prévision des ventes sur 12 mois (tendance et saisonnalité) et atterrissage de fin d\'année face aux objectifs, seuil de rentabilité et point mort, simulateur (prix, volumes, coûts), rentabilité par client, article et catégorie.',
    'category' => 'Pilotage',
    'icon' => 'query_stats',
    'depends' => ['ventes'],
    'sort' => 6,
    'setup' => \App\Modules\Pilotage\PilotageSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Pilotage\Dashboard::class,
    'permissions' => [
        'pilotage.view' => 'Consulter les indicateurs de gestion, prévisions et rentabilité',
        'pilotage.manage' => 'Fixer les objectifs de l\'année',
    ],
    'sync' => [],
];
