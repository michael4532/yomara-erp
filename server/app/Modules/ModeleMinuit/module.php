<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_minuit',
    'name' => 'Modèle Minuit',
    'description' => 'Modèle à bandeau oblique noir et jaune, grand contraste : sécurité, transport, énergie. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 80,
    'permissions' => [],
    'sync' => [],
];
