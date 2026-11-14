<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_graphite',
    'name' => 'Modèle Graphite',
    'description' => 'Modèle à coins géométriques anthracite et orange, en-tête deux tons : BTP, industrie, logistique. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
