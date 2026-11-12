<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_kente',
    'name' => 'Modèle Kente',
    'description' => 'Modèle à frise inspirée des tissages, or, vert et rouge en tête et en pied : commerce, artisanat, culture. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
