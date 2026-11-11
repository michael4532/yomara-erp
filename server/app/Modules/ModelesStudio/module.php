<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modeles_studio',
    'name' => 'Studio de modèles',
    'description' => 'Créez vos propres modèles de factures, proformas, devis, commandes et acomptes par glisser-déposer : blocs, couleurs, police, en-tête, titres, aperçu en direct. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'design_services',
    'depends' => ['ventes'],
    'sort' => 85,
    'permissions' => [],
    'sync' => [],
];
