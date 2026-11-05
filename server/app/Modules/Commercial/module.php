<?php

return [
    'key' => 'commercial',
    'name' => 'Force de vente & terrain',
    'description' => 'L\'espace du service commercial : objectifs par commercial (chiffre d\'affaires, visites, nouveaux clients, devis) avec le réalisé calculé, classement de l\'équipe, tournées de terrain avec véhicule demandé au parc automobile, visites et comptes rendus (résultat, montant en jeu, prochaine étape relancée dans le CRM).',
    'category' => 'Ventes & clients',
    'icon' => 'storefront',
    'depends' => ['core'],
    'sort' => 16,
    'setup' => \App\Modules\Commercial\CommercialSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Commercial\Dashboard::class,
    'permissions' => [
        'commercial.field' => 'Planifier ses tournées et visites, rédiger ses comptes rendus, voir ses objectifs',
        'commercial.view' => 'Voir les performances, visites et tournées de toute l\'équipe',
        'commercial.manage' => 'Fixer les objectifs et gérer l\'activité de toute l\'équipe commerciale',
    ],
    'sync' => [],
];
