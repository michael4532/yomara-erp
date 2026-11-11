<?php

// Pack d'espace en plus pour les Documents (GED) : s'ajoute à l'espace partagé de la société tant qu'il est actif.
return [
    'key' => 'stockage_10',
    'name' => 'Stockage +10 Go',
    'description' => 'Espace de stockage en plus pour les documents de la société (+10 Go), partagé par tout le personnel.',
    'category' => 'Collaboration',
    'icon' => 'cloud_upload',
    'depends' => ['documents'],
    'sort' => 72,
    'permissions' => [],
    'sync' => [],
];
