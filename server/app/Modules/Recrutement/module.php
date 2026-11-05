<?php

return [
    'key' => 'recrutement',
    'name' => 'Recrutement',
    'description' => 'Offres d\'emploi publiées sur une page Emplois, candidatures avec CV, suivi par étapes, entretiens et avis des intervenants, messages aux candidats, embauche qui crée la fiche salarié, statistiques.',
    'category' => 'Ressources humaines',
    'icon' => 'person_search',
    'depends' => ['rh'],
    'sort' => 63,
    'setup' => \App\Modules\Recrutement\RecrutementSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Recrutement\Dashboard::class,
    'permissions' => [
        'recrutement.view' => 'Consulter les offres et les candidatures',
        'recrutement.manage' => 'Publier les offres, gérer les candidatures, embaucher',
        'recrutement.interview' => 'Mener des entretiens et donner son avis',
    ],
    'sync' => [
        'rc_jobs' => \App\Modules\Recrutement\Models\Job::class,
        'rc_applications' => \App\Modules\Recrutement\Models\Application::class,
        'rc_interviews' => \App\Modules\Recrutement\Models\Interview::class,
    ],
];
