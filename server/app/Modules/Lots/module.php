<?php

return [
    'key' => 'lots',
    'name' => 'Lots, séries & péremption',
    'description' => 'Suivi par numéro de lot ou de série à chaque entrée et sortie, dates de péremption, sortie automatique du lot qui périme en premier (FEFO), lots périmés bloqués, alertes, mise au rebut, traçabilité complète d\'un lot (fournisseur, clients) pour les rappels de produits.',
    'category' => 'Achats & stocks',
    'icon' => 'qr_code_scanner',
    'depends' => ['stock'],
    'sort' => 31,
    'dashboard' => \App\Modules\Lots\Dashboard::class,
    'permissions' => [],
    'sync' => [
        'stock_lots' => \App\Modules\Lots\Models\Lot::class,
    ],
];
