<?php

return [
    'key' => 'ia',
    'name' => 'Assistant IA',
    'description' => 'Questions en français sur vos ventes, impayés, stock, trésorerie et dépenses ; rédaction d\'e-mails, relances et fiches articles.',
    'category' => 'Pilotage',
    'icon' => 'auto_awesome',
    'depends' => ['core'],
    'sort' => 95,
    'permissions' => [
        'ia.use' => 'Utiliser l\'assistant IA (dans la limite de ses droits sur les données)',
        'ia.manage' => 'Paramétrer l\'assistant (fournisseur, clé, quota)',
    ],
    'sync' => [],
];
