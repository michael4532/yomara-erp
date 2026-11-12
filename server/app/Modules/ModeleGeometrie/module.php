<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_geometrie',
    'name' => 'Modèle Géométrie',
    'description' => 'Modèle à cercles en arrière-plan, grand titre et montant dû mis en avant : agences, studios, startups. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
