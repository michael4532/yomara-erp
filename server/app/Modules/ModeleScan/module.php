<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_scan',
    'name' => 'Modèle Scan',
    'description' => 'Modèle bleu nuit et turquoise à coins géométriques, avec un grand code QR à scanner pour payer en ligne ou enregistrer vos coordonnées. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
