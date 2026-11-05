<?php

return [
    'key' => 'restaurant',
    'name' => 'Restaurant & bar',
    'description' => 'Plan de salle, prise de commande à table, envoi en cuisine et au bar, écran cuisine, additions partagées, encaissement par la caisse.',
    'category' => 'Caisse & restaurant',
    'icon' => 'restaurant',
    'depends' => ['pos'],
    'sort' => 41,
    'provider' => \App\Modules\Restaurant\RestaurantModuleProvider::class,
    'dashboard' => \App\Modules\Restaurant\Dashboard::class,
    'permissions' => [
        'restaurant.serve' => 'Prendre les commandes en salle',
        'restaurant.kitchen' => 'Utiliser l\'écran cuisine / bar',
        'restaurant.manage' => 'Gérer le plan de salle, les postes, les annulations et les statistiques',
    ],
    'sync' => [
        'rs_floors' => \App\Modules\Restaurant\Models\Floor::class,
        'rs_tables' => \App\Modules\Restaurant\Models\Table::class,
        'rs_stations' => \App\Modules\Restaurant\Models\Station::class,
        'rs_orders' => \App\Modules\Restaurant\Models\Order::class,
        'rs_order_lines' => \App\Modules\Restaurant\Models\OrderLine::class,
    ],
];
