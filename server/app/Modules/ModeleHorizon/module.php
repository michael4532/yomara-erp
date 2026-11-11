<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_horizon',
    'name' => 'Modèle Horizon',
    'description' => 'Modèle de facture et devis à bandeau émeraude, accents corail et lignes zébrées. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 81,
    'permissions' => [],
    'sync' => [],
];
