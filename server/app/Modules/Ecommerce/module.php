<?php

return [
    'key' => 'ecommerce',
    'name' => 'Boutique en ligne',
    'description' => 'Le e-commerce relié à l\'ERP : tableau des commandes (site, WhatsApp, Facebook, téléphone), stock réservé dès la commande, bons de préparation, facturation et sortie de stock à l\'expédition, livraison créée dans la Logistique avec encaissement à la livraison, paiements Mobile Money enregistrés en trésorerie, catalogue pour Facebook, Instagram, WhatsApp et Google Shopping, statistiques de conversion.',
    'category' => 'Marketing & communication',
    'icon' => 'shopping_cart_checkout',
    'depends' => ['sites', 'ventes'],
    'sort' => 50,
    'provider' => \App\Modules\Ecommerce\EcommerceModuleProvider::class,
    'permissions' => [
        'ecommerce.view' => 'Voir les commandes en ligne et les statistiques de la boutique',
        'ecommerce.manage' => 'Traiter les commandes : confirmer, préparer, expédier, encaisser, annuler ; saisir les commandes WhatsApp',
        'ecommerce.settings' => 'Régler la boutique en ligne : réservation du stock, livraisons, flux catalogue',
    ],
    'sync' => [],
];
