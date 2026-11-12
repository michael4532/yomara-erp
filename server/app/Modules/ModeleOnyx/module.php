<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_onyx',
    'name' => 'Modèle Onyx',
    'description' => 'Modèle de facture, proforma et devis à bandeau noir pleine largeur, titres à empattements dorés et total sur fond noir : hôtels, joaillerie, cabinets. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
