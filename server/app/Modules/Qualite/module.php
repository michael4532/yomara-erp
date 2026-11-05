<?php

return [
    'key' => 'qualite',
    'name' => 'Qualité',
    'description' => 'Contrôles qualité à la réception, en production, sur les produits finis, l\'hygiène ou la sécurité : plans de contrôle avec leurs critères, contrôles réalisés depuis l\'application (conforme / non conforme, taux de conformité), non-conformités et réclamations clients ou fournisseurs (gravité, analyse des causes, coût de non-qualité), actions correctives et préventives avec responsable et échéance, rappels et indicateurs.',
    'category' => 'Opérations',
    'icon' => 'verified',
    'depends' => ['core'],
    'sort' => 79,
    'setup' => \App\Modules\Qualite\QualiteSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Qualite\Dashboard::class,
    'permissions' => [
        'qualite.report' => 'Déclarer une non-conformité ou une réclamation',
        'qualite.inspect' => 'Réaliser les contrôles qualité',
        'qualite.view' => 'Consulter les contrôles, non-conformités et actions',
        'qualite.manage' => 'Gérer les plans de contrôle, non-conformités et actions correctives',
    ],
    'sync' => [],
];
