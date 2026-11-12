<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_savane',
    'name' => 'Modèle Savane',
    'description' => 'Modèle à vague brun et ocre et typographie chaleureuse : restauration, agroalimentaire, hôtellerie. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
