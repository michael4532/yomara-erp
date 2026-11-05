<?php

return [
    'key' => 'contenus',
    'name' => 'Stratégie marketing & communication',
    'description' => 'L\'espace du service marketing et communication : stratégies et plans (objectifs, cibles, message, canaux, budget), calendrier éditorial des réseaux sociaux avec circuit de validation et rappels, actions et campagnes avec retour sur investissement, fiches concurrents et veille concurrentielle, relations presse et communication interne.',
    'category' => 'Marketing & communication',
    'icon' => 'calendar_month',
    'depends' => ['core'],
    'sort' => 61,
    'setup' => \App\Modules\Contenus\ContenusSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Contenus\Dashboard::class,
    'permissions' => [
        'contenus.view' => 'Consulter la stratégie, le calendrier éditorial et la veille',
        'contenus.manage' => 'Rédiger et planifier les publications, gérer stratégies, actions, concurrents et presse',
        'contenus.validate' => 'Valider les publications avant leur mise en ligne',
        'contenus.watch' => 'Signaler des observations sur les concurrents (commerciaux sur le terrain)',
    ],
    'sync' => [],
];
