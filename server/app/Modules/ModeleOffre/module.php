<?php

// Modèle de document premium (achat définitif) : débloque le modèle dans la bibliothèque des factures, devis et proformas.
return [
    'key' => 'modele_offre',
    'name' => 'Modèle Offre commerciale',
    'description' => 'Devis et proformas présentés en offre commerciale : texte d\'introduction, validité, « Bon pour accord » signé par le client. Achat définitif.',
    'category' => 'Ventes & clients',
    'icon' => 'description',
    'depends' => ['ventes'],
    'sort' => 82,
    'permissions' => [],
    'sync' => [],
];
