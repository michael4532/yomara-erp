<?php

return [
    'key' => 'production',
    'name' => 'Fabrication (MRP)',
    'description' => 'Nomenclatures et recettes, coût de revient et marge, ordres de fabrication (consommation des composants, entrée du produit fini), recettes déduites du stock à la vente, besoins en composants.',
    'category' => 'Opérations',
    'icon' => 'precision_manufacturing',
    'depends' => ['stock'],
    'sort' => 58,
    'provider' => \App\Modules\Production\ProductionModuleProvider::class,
    'dashboard' => \App\Modules\Production\Dashboard::class,
    'permissions' => [
        'production.view' => 'Consulter les nomenclatures et les ordres de fabrication',
        'production.operate' => 'Atelier : démarrer et terminer les ordres de fabrication',
        'production.manage' => 'Gérer les nomenclatures, planifier et annuler les ordres de fabrication',
    ],
    'sync' => [
        'mrp_boms' => \App\Modules\Production\Models\Bom::class,
        'mrp_orders' => \App\Modules\Production\Models\ProductionOrder::class,
    ],
];
