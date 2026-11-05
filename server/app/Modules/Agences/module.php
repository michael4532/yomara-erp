<?php

return [
    'key' => 'agences',
    'name' => 'Multi-agences',
    'description' => 'Agences, succursales et points de vente : ventes, caisses, stocks, dépenses et utilisateurs par agence, accès limité, tableau de bord comparatif.',
    'category' => 'Pilotage',
    'icon' => 'store_mall_directory',
    'depends' => ['core'],
    'sort' => 6,
    'provider' => \App\Modules\Agences\AgencesModuleProvider::class,
    'permissions' => [
        'agences.view' => 'Voir le tableau de bord des agences',
        'agences.manage' => 'Créer et paramétrer les agences, y rattacher les utilisateurs',
    ],
    'sync' => [
        'branches' => \App\Modules\Agences\Models\Branch::class,
    ],
];
