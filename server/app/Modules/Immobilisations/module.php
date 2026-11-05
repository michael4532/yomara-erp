<?php

return [
    'key' => 'immobilisations',
    'name' => 'Immobilisations',
    'description' => 'Registre des immobilisations (véhicules, matériel, mobilier, logiciels…), plans d\'amortissement linéaire ou dégressif SYSCOHADA, dotations comptabilisées, cessions et mises au rebut, tableau des immobilisations.',
    'category' => 'Finance',
    'icon' => 'domain',
    'depends' => ['core'],
    'sort' => 53,
    'setup' => \App\Modules\Immobilisations\ImmobilisationsSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Immobilisations\Dashboard::class,
    'permissions' => [
        'immobilisations.view' => 'Consulter les immobilisations',
        'immobilisations.manage' => 'Gérer les immobilisations, comptabiliser les dotations et les sorties',
    ],
    'sync' => [
        'im_categories' => \App\Modules\Immobilisations\Models\AssetCategory::class,
        'im_assets' => \App\Modules\Immobilisations\Models\Asset::class,
    ],
];
