<?php

return [
    'key' => 'flotte',
    'name' => 'Parc automobile',
    'description' => 'Les véhicules de l\'entreprise suivis par la logistique : demandes de véhicule par les commerciaux et techniciens, attribution sans double réservation, sortie et retour au compteur (heure, conducteur, accompagnants, client visité, km parcourus), carburant et consommation, entretien, assurance et visite technique avec alertes, coûts par véhicule.',
    'category' => 'Opérations',
    'icon' => 'directions_car',
    'depends' => ['core'],
    'sort' => 46,
    'setup' => \App\Modules\Flotte\FlotteSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Flotte\Dashboard::class,
    'permissions' => [
        'flotte.request' => 'Demander un véhicule et déclarer ses sorties et pleins',
        'flotte.approve' => 'Attribuer les véhicules et décider des demandes',
        'flotte.view' => 'Consulter tout le parc, les sorties et les coûts',
        'flotte.manage' => 'Gérer les véhicules, l\'entretien et les frais',
    ],
    'sync' => [],
];
