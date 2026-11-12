<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_azur',
    'name' => 'Modèle Azur',
    'description' => 'Modèle à bandeau bleu roi, filigrane du logo et lignes zébrées : PME, distribution, services. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
