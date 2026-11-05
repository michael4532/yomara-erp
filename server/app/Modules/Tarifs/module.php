<?php

return [
    'key' => 'tarifs',
    'name' => 'Tarifs & conditionnements',
    'description' => 'Listes de prix par client (grossistes, revendeurs, VIP…) ou par période (promotions), prix fixes ou remises par article ou catégorie, remises par quantité ; conditionnements (carton, pack, sac) avec leur code-barres et leur prix, stock tenu à l\'unité.',
    'category' => 'Achats & stocks',
    'icon' => 'sell',
    'depends' => ['core'],
    'sort' => 12,
    'setup' => \App\Modules\Tarifs\TarifsSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'tarifs.manage' => 'Gérer les listes de prix',
    ],
    'sync' => [
        'pl_lists' => \App\Modules\Tarifs\Models\PriceList::class,
    ],
];
