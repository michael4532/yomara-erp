<?php

return [
    'key' => 'dev',
    'name' => 'Développement logiciel',
    'description' => 'L\'espace du service développement : applications et environnements, backlog (récits utilisateur, bugs, tâches techniques) avec tableau kanban, sprints et avancement en points, branches et pull requests, revue de code et recette, versions et mises en production avec notes de version générées.',
    'category' => 'Opérations',
    'icon' => 'code',
    'depends' => ['core'],
    'sort' => 49,
    'setup' => \App\Modules\Dev\DevSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Dev\Dashboard::class,
    'permissions' => [
        'dev.view' => 'Consulter les applications, le backlog, les sprints et les versions',
        'dev.contribute' => 'Créer des tâches et faire avancer celles qui lui sont assignées',
        'dev.manage' => 'Gérer les applications, sprints, versions et toutes les tâches',
    ],
    'sync' => [],
];
