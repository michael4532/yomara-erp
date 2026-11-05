<?php

return [
    'key' => 'evenements',
    'name' => 'Événements & salons',
    'description' => 'Salons, foires, festivals, conférences : plan des stands et emplacements, vente d\'espaces aux exposants (option, confirmation), packs sponsors et options, acompte et solde facturés automatiquement, page publique de réservation, accueil des exposants, taux de remplissage et chiffre d\'affaires.',
    'category' => 'Métiers',
    'icon' => 'storefront',
    'depends' => ['ventes'],
    'sort' => 15,
    'setup' => \App\Modules\Evenements\EvenementsSetup::class,
    'setup_version' => 1,
    'provider' => \App\Modules\Evenements\EvenementsModuleProvider::class,
    'dashboard' => \App\Modules\Evenements\Dashboard::class,
    'permissions' => [
        'evenements.view' => 'Consulter les événements, le plan et les réservations',
        'evenements.sell' => 'Réserver des espaces pour les exposants',
        'evenements.manage' => 'Gérer les événements, le plan, les tarifs et la facturation',
    ],
    'sync' => [
        'ev_events' => \App\Modules\Evenements\Models\Event::class,
        'ev_spaces' => \App\Modules\Evenements\Models\Space::class,
        'ev_packages' => \App\Modules\Evenements\Models\Package::class,
        'ev_bookings' => \App\Modules\Evenements\Models\Booking::class,
    ],
];
