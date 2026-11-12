<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_lagon',
    'name' => 'Modèle Lagon',
    'description' => 'Modèle à vague bleu lagon en tête et en pied, police géométrique : services, tourisme, transport. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
