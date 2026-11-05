<?php

return [
    'key' => 'woocommerce',
    'name' => 'Connecteur WooCommerce',
    'description' => 'Votre boutique WordPress / WooCommerce reliée au logiciel : produits, prix et stock publiés et tenus à jour automatiquement depuis l\'ERP, commandes du site importées en direct (commande client, facture, encaissement), clients créés ou reconnus, statut de la commande renvoyé au site, plusieurs boutiques possibles.',
    'category' => 'Marketing & communication',
    'icon' => 'storefront',
    'depends' => ['ventes'],
    'sort' => 52,
    'permissions' => [
        'woo.view' => 'Voir les boutiques WooCommerce, les produits publiés et les commandes importées',
        'woo.manage' => 'Connecter une boutique, choisir les produits publiés, synchroniser, régler le connecteur',
    ],
    'sync' => [],
];
