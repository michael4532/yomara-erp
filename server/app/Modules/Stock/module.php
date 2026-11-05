<?php

return [
    'key' => 'stock',
    'name' => 'Stock & Inventaire',
    'description' => 'Entrepôts, mouvements, transferts, inventaires, alertes de rupture.',
    'category' => 'Achats & stocks',
    'icon' => 'inventory',
    'depends' => ['core'],
    'sort' => 30,
    'provider' => \App\Modules\Stock\StockModuleProvider::class,
    'dashboard' => \App\Modules\Stock\Dashboard::class,
    'permissions' => [
        'stock.view' => 'Voir le stock',
        'stock.manage' => 'Gérer entrepôts, ajustements, transferts, inventaires',
    ],
    'sync' => [
        'warehouses' => \App\Modules\Stock\Models\Warehouse::class,
        'stock_moves' => \App\Modules\Stock\Models\StockMove::class,
    ],
];
