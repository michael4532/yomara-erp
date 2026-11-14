<?php

// Pack de crédits X : s'ajoute chaque mois aux crédits inclus tant qu'il est actif (X facture chaque publication à l'éditeur).
return [
    'key' => 'credits_x_1000',
    'name' => 'Crédits X +1000 / mois',
    'description' => '1000 crédits de publication sur X (Twitter) en plus chaque mois : 1 crédit par publication, davantage pour une publication avec un lien.',
    'category' => 'Marketing & communication',
    'icon' => 'share',
    'depends' => ['social'],
    'sort' => 63,
    'permissions' => [],
    'sync' => [],
];
