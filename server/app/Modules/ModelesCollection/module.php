<?php

// Collection premium : un seul achat débloque tous les modèles premium de la bibliothèque, présents et à venir.
return [
    'key' => 'modeles_collection',
    'name' => 'Collection de modèles premium',
    'description' => 'Tous les modèles premium de factures, proformas et devis (Prestige, Onyx, Royal, Atelier, Lagon, Kente, Oblique…), et ceux à venir, en un seul achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'workspace_premium',
    'depends' => ['ventes'],
    'sort' => 79,
    'permissions' => [],
    'sync' => [],
];
