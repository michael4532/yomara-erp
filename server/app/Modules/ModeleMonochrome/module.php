<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_monochrome',
    'name' => 'Modèle Monochrome',
    'description' => 'Modèle noir et blanc à très grand titre et cases d\'informations, économe en encre : designers, consultants, freelances. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
