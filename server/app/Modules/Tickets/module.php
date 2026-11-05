<?php

return [
    'key' => 'tickets',
    'name' => 'Demandes internes & tickets',
    'description' => 'Tickets entre services : urgence, attribution, suivi des délais, hiérarchie, statistiques et rapports automatiques.',
    'category' => 'Collaboration',
    'icon' => 'confirmation_number',
    'depends' => ['core'],
    'sort' => 70,
    'dashboard' => \App\Modules\Tickets\Dashboard::class,
    'setup' => \App\Modules\Tickets\TicketSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'tickets.manage' => 'Organiser les départements, chefs et collaborateurs',
        'tickets.view_all' => 'Direction : voir les tickets et statistiques de tous les services',
    ],
    'sync' => [],
];
