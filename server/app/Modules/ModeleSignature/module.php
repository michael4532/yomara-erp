<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_signature',
    'name' => 'Modèle Signature',
    'description' => 'Modèle à double cadre noir et or, en-tête centré et titres élégants : notaires, joaillerie, prestige. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
