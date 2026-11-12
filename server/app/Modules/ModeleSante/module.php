<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_sante',
    'name' => 'Modèle Santé',
    'description' => 'Modèle sarcelle à filets en tête et en pied et grand titre : cliniques, pharmacies, laboratoires. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
