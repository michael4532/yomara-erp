<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_atelier',
    'name' => 'Modèle Atelier',
    'description' => 'Modèle à typographie à empattements et double cadre ardoise et cuivre : architectes, avocats, notaires. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
