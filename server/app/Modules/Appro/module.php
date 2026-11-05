<?php

return [
    'key' => 'appro',
    'name' => 'Importations, comparatifs & locations',
    'description' => 'Pour les achats techniques (électricité, BTP, industrie…) : dossiers d\'importation multi-pays avec devise, fret, assurance, droits de douane et transit répartis sur chaque article (coût de revient réel), cahiers des charges en PDF envoyés aux fournisseurs, comparatifs de prix entre fournisseurs avec attribution et bon de commande, locations de matériel et services externes avec durée, rappels et facture, historique et évaluation de chaque fournisseur dans la durée, exports PDF et Excel, tableau de bord.',
    'category' => 'Achats & stocks',
    'icon' => 'local_shipping',
    'depends' => ['achats'],
    'sort' => 21,
    'setup' => \App\Modules\Appro\ApproSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Appro\Dashboard::class,
    'permissions' => [
        'appro.view' => 'Consulter importations, comparatifs, cahiers des charges, locations et historique fournisseurs',
        'appro.manage' => 'Gérer importations, comparatifs, cahiers des charges et locations',
    ],
    'sync' => [],
];
