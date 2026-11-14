<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_cobalt',
    'name' => 'Modèle Cobalt',
    'description' => 'Modèle à arc bleu cobalt et cyan, tableau clair et total teinté : tech, services, start-up. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
