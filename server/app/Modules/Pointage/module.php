<?php

return [
    'key' => 'pointage',
    'name' => 'Pointage & présence',
    'description' => 'Borne de pointage avec code PIN (hors ligne), pointage depuis le téléphone du salarié, présents / retards / absents du jour, feuilles de présence, heures supplémentaires envoyées en paie.',
    'category' => 'Ressources humaines',
    'icon' => 'fingerprint',
    'depends' => ['rh'],
    'sort' => 62,
    'provider' => \App\Modules\Pointage\PointageModuleProvider::class,
    'setup' => \App\Modules\Pointage\PointageSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Pointage\Dashboard::class,
    'permissions' => [
        'pointage.view' => 'Voir la présence du jour et les feuilles de présence',
        'pointage.manage' => 'Gérer les horaires, codes PIN, corriger les pointages, envoyer en paie',
        'pointage.kiosk' => 'Borne de pointage (tablette à l\'entrée)',
        'pointage.self' => 'Pointer soi-même depuis son téléphone',
    ],
    'sync' => [
        'pt_schedules' => \App\Modules\Pointage\Models\Schedule::class,
        'pt_badges' => \App\Modules\Pointage\Models\Badge::class,
        'pt_punches' => \App\Modules\Pointage\Models\Punch::class,
    ],
];
