<?php

return [
    'key' => 'logistique',
    'name' => 'Logistique & Livraisons',
    'description' => 'Bons de livraison, tournées, livreurs et véhicules, zones et frais, preuve de livraison (signature, photo), paiement à la livraison, suivi en ligne pour le client.',
    'category' => 'Opérations',
    'icon' => 'local_shipping',
    'depends' => ['stock'],
    'sort' => 55,
    'provider' => \App\Modules\Logistique\LogistiqueModuleProvider::class,
    'dashboard' => \App\Modules\Logistique\Dashboard::class,
    'permissions' => [
        'logistique.view' => 'Consulter les livraisons et tournées',
        'logistique.deliver' => 'Livreur : voir ses livraisons, les mettre à jour, encaisser',
        'logistique.manage' => 'Créer et planifier les livraisons, tournées, zones et véhicules',
    ],
    'sync' => [
        'lg_zones' => \App\Modules\Logistique\Models\Zone::class,
        'lg_vehicles' => \App\Modules\Logistique\Models\Vehicle::class,
        'lg_tours' => \App\Modules\Logistique\Models\Tour::class,
        'lg_deliveries' => \App\Modules\Logistique\Models\Delivery::class,
    ],
];
