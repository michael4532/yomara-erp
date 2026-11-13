<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_emeraude',
    'name' => 'Modèle Émeraude',
    'description' => 'Modèle à bandeau vert émeraude et or, titres à empattements et filigrane du logo : agro-industrie, banques, hôtels. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
