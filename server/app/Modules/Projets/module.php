<?php

return [
    'key' => 'projets',
    'name' => 'Projets & Tâches',
    'description' => 'Projets clients ou internes, tâches en Kanban, jalons, planning, feuilles de temps et facturation au temps passé ou par jalon.',
    'category' => 'Opérations',
    'icon' => 'task_alt',
    'depends' => ['core'],
    'sort' => 60,
    'provider' => \App\Modules\Projets\ProjetsModuleProvider::class,
    'dashboard' => \App\Modules\Projets\Dashboard::class,
    'permissions' => [
        'projets.view' => 'Consulter les projets, tâches et temps',
        'projets.contribute' => 'Mettre à jour ses tâches et saisir son temps',
        'projets.manage' => 'Créer et piloter les projets, budgets et facturation',
    ],
    'sync' => [
        'prj_projects' => \App\Modules\Projets\Models\Project::class,
        'prj_tasks' => \App\Modules\Projets\Models\Task::class,
        'prj_milestones' => \App\Modules\Projets\Models\Milestone::class,
        'prj_timesheets' => \App\Modules\Projets\Models\Timesheet::class,
    ],
];
