<?php

return [
    'key' => 'planning',
    'name' => 'Planning des ressources',
    'description' => 'Qui travaille sur quoi, semaine par semaine : affectations de l\'équipe aux projets et clients, capacité de chacun (horaires, congés, jours fériés), taux d\'occupation, surcharges et disponibilités, prévu comparé au temps réellement saisi.',
    'category' => 'Opérations',
    'icon' => 'view_timeline',
    'depends' => ['core'],
    'sort' => 61,
    'setup' => \App\Modules\Planning\PlanningSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Planning\Dashboard::class,
    'permissions' => [
        'planning.view' => 'Consulter le planning de l\'équipe',
        'planning.manage' => 'Affecter les ressources et régler la capacité',
    ],
    'sync' => [
        'pl_assignments' => \App\Modules\Planning\Models\Assignment::class,
    ],
];
