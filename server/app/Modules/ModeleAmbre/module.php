<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_ambre',
    'name' => 'Modèle Ambre',
    'description' => 'Modèle à arc chocolat et ambre, titres à empattements et filets fins : restauration, artisanat, mode. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
