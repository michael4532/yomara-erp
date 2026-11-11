<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_ivoire',
    'name' => 'Modèle Ivoire',
    'description' => 'Modèle de facture orange et vert en deux tons, chaleureux et très lisible. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 83,
    'permissions' => [],
    'sync' => [],
];
