<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_saphir',
    'name' => 'Modèle Saphir',
    'description' => 'Modèle à en-tête bleu nuit en arc, liseré or et titres élégants : cabinets, immobilier, hôtellerie. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
