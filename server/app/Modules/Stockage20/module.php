<?php

// Pack d'espace en plus pour les Documents (GED) : s'ajoute à l'espace partagé de la société tant qu'il est actif.
return [
    'key' => 'stockage_20',
    'name' => 'Stockage +20 Go',
    'description' => 'Espace de stockage en plus pour les documents de la société (+20 Go), partagé par tout le personnel.',
    'category' => 'Collaboration',
    'icon' => 'cloud_upload',
    'depends' => ['documents'],
    'sort' => 73,
    'permissions' => [],
    'sync' => [],
];
