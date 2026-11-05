<?php

return [
    'key' => 'location',
    'name' => 'Location de matériel',
    'description' => 'Louez votre matériel (engins, groupes électrogènes, sono, chapiteaux, chaises, véhicules…) : parc à louer avec tarifs à la journée, à la semaine et au mois, disponibilités sans double réservation, contrats avec caution, livraison, sortie et retour, retards et dégâts facturés, restitution de la caution, facture en un clic, taux d\'utilisation.',
    'category' => 'Métiers',
    'icon' => 'key',
    'depends' => ['ventes'],
    'sort' => 77,
    'setup' => \App\Modules\Location\LocationSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Location\Dashboard::class,
    'permissions' => [
        'location.view' => 'Consulter le matériel et les contrats de location',
        'location.manage' => 'Gérer le matériel, les contrats, sorties, retours et cautions',
    ],
    'sync' => [],
];
