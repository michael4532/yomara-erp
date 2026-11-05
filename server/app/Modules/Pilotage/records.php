<?php

use App\Modules\Pilotage\PilotageService as S;

return [
    'hooks' => S::class,
    'resources' => [
        'targets' => [
            'table' => 'pl_targets', 'label' => 'Objectif', 'plural' => 'Objectifs', 'icon' => 'flag',
            'view' => 'pilotage.view|pilotage.manage', 'manage' => 'pilotage.manage', 'order' => 'year desc,month asc', 'search' => ['title', 'notes'],
            'hint' => 'Un objectif par mois (ou un objectif annuel, réparti selon la saisonnalité des ventes).',
            'fields' => [
                ['key' => 'title', 'label' => 'Période', 'readonly' => true, 'list' => true],
                ['key' => 'year', 'label' => 'Année', 'type' => 'number', 'required' => true, 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'month', 'label' => 'Mois', 'type' => 'select', 'half' => true, 'options' => S::MONTH_OPTIONS, 'hint' => 'Vide : objectif de toute l\'année'],
                ['key' => 'revenue', 'label' => 'Chiffre d\'affaires HT visé', 'type' => 'money', 'required' => true, 'list' => true, 'half' => true],
                ['key' => 'margin', 'label' => 'Marge brute visée', 'type' => 'money', 'list' => true, 'half' => true],
                ['key' => 'achieved', 'label' => 'Réalisé', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'notes', 'label' => 'Commentaire', 'type' => 'textarea'],
            ],
        ],
    ],
];
