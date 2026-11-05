<?php

return [
    'key' => 'immobilier',
    'name' => 'Gestion locative',
    'description' => 'Immeubles, villas, magasins et bureaux en location, pour votre compte ou pour des propriétaires : lots, baux, caution et avance, appels de loyer et factures chaque mois, encaissements, quittances, relances des impayés par WhatsApp ou SMS, révision des loyers, dépenses et travaux, relevés de gérance et reversements aux propriétaires, taux d\'occupation.',
    'category' => 'Métiers',
    'icon' => 'apartment',
    'depends' => ['ventes'],
    'sort' => 60,
    'permissions' => [
        'immobilier.view' => 'Consulter les biens, baux, loyers et impayés',
        'immobilier.manage' => 'Gérer les biens, lots et baux ; émettre et encaisser les loyers ; relancer',
        'immobilier.owners' => 'Relevés de gérance et reversements aux propriétaires',
    ],
    'sync' => [],
];
