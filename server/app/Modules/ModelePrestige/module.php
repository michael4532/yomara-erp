<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_prestige',
    'name' => 'Modèle Prestige',
    'description' => 'Modèle de facture, proforma et devis noir et or, en-tête centré : pour l\'hôtellerie, le luxe, les cabinets. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
