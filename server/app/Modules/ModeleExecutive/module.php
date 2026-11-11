<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_executive',
    'name' => 'Modèle Executive',
    'description' => 'Modèle de facture bleu ardoise à liseré latéral et cartouche, pour les grands comptes et les marchés publics. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 84,
    'permissions' => [],
    'sync' => [],
];
