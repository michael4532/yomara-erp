<?php

return [
    'key' => 'fonds',
    'name' => 'Appels de fonds',
    'description' => 'Demandes de moyens (argent, équipement, prestations) d\'un service à sa hiérarchie ou au siège : liste chiffrée des besoins envoyée par e-mail avec le PDF, demande d\'explication, approbation ligne par ligne, service chargé de fournir, mise à disposition (virement entre comptes de trésorerie), accusé de réception, historique complet, relances et tableau de bord.',
    'category' => 'Finance',
    'icon' => 'request_page',
    'depends' => ['core'],
    'sort' => 52,
    'setup' => \App\Modules\Fonds\FondsSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Fonds\Dashboard::class,
    'permissions' => [
        'fonds.request' => 'Faire des appels de fonds pour son service',
        'fonds.approve' => 'Recevoir et décider des appels de fonds (responsable, direction, siège)',
        'fonds.provide' => 'Mettre les fonds ou le matériel à disposition',
        'fonds.view' => 'Consulter tous les appels de fonds',
        'fonds.manage' => 'Gérer tous les appels de fonds et les réglages',
    ],
    'sync' => [],
];
