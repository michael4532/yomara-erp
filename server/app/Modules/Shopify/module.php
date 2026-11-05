<?php

return [
    'key' => 'shopify',
    'name' => 'Connecteur Shopify',
    'description' => 'Votre boutique Shopify reliée au logiciel : produits, prix et stock publiés et tenus à jour depuis l\'ERP, commandes importées en direct (commande client, facture, encaissement), clients reconnus ou créés, commande marquée payée sur Shopify quand l\'ERP encaisse.',
    'category' => 'Marketing & communication',
    'icon' => 'shopping_bag',
    'depends' => ['ventes'],
    'sort' => 53,
    'permissions' => [
        'woo.view' => 'Voir les boutiques connectées, les produits publiés et les commandes importées',
        'woo.manage' => 'Connecter une boutique, choisir les produits publiés, synchroniser, régler le connecteur',
    ],
    'sync' => [],
];
