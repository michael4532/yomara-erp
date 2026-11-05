<?php

return [
    'key' => 'juridique',
    'name' => 'Juridique & contrats',
    'description' => 'L\'espace du service juridique : registre de tous les contrats (clients, fournisseurs, travail, baux, partenariats, confidentialité) avec échéances, préavis et tacite reconduction suivis, rédaction à partir de modèles remplis automatiquement, PDF et signature électronique, contentieux et dossiers (juridiction, avocat, enjeu, risque), audiences et échéances avec rappels.',
    'category' => 'Juridique',
    'icon' => 'gavel',
    'depends' => ['core'],
    'sort' => 75,
    'setup' => \App\Modules\Juridique\JuridiqueSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Juridique\Dashboard::class,
    'permissions' => [
        'juridique.view' => 'Consulter les contrats, dossiers et échéances',
        'juridique.manage' => 'Rédiger et gérer les contrats, modèles, dossiers et échéances',
    ],
    'sync' => [],
];
