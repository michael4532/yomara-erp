<?php

// Pack d'espace en plus pour les Documents (GED) : s'ajoute à l'espace partagé de la société tant qu'il est actif.
return [
    'key' => 'stockage_50',
    'name' => 'Stockage +50 Go',
    'description' => 'Espace de stockage en plus pour les documents de la société (+50 Go), partagé par tout le personnel.',
    'category' => 'Collaboration',
    'icon' => 'cloud_upload',
    'depends' => ['documents'],
    'sort' => 74,
    'permissions' => [],
    'sync' => [],
];
