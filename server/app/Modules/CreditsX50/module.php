<?php

// Pack de crédits X : s'ajoute chaque mois aux crédits inclus tant qu'il est actif (X facture chaque publication à l'éditeur).
return [
    'key' => 'credits_x_50',
    'name' => 'Crédits X +50 / mois',
    'description' => '50 crédits de publication sur X (Twitter) en plus chaque mois : 1 crédit par publication, davantage pour une publication avec un lien.',
    'category' => 'Marketing & communication',
    'icon' => 'share',
    'depends' => ['social'],
    'sort' => 63,
    'permissions' => [],
    'sync' => [],
];
