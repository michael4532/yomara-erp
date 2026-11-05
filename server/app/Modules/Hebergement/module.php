<?php

return [
    'key' => 'hebergement',
    'name' => 'Hébergement, domaines & licences',
    'description' => 'Pour les entreprises du numérique : noms de domaine, hébergements, certificats SSL, e-mails pro, maintenance et licences de vos clients, avec leurs échéances, factures de renouvellement automatiques, rappels au client, liste des renouvellements à faire chez le fournisseur et marge par service.',
    'category' => 'Métiers',
    'icon' => 'dns',
    'depends' => ['ventes'],
    'sort' => 16,
    'setup' => \App\Modules\Hebergement\HebergementSetup::class,
    'setup_version' => 1,
    'provider' => \App\Modules\Hebergement\HebergementModuleProvider::class,
    'dashboard' => \App\Modules\Hebergement\Dashboard::class,
    'permissions' => [
        'hebergement.view' => 'Consulter les services clients et leurs échéances',
        'hebergement.manage' => 'Gérer les services, renouvellements et rappels',
    ],
    'sync' => [
        'hb_services' => \App\Modules\Hebergement\Models\HostedService::class,
    ],
];
