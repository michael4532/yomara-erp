<?php

return [
    'key' => 'paie',
    'name' => 'Paie multi-pays',
    'description' => 'Bulletins de paie et déclarations pour la Côte d\'Ivoire (CNPS, CMU, ITS, FDFP), les pays de l\'UEMOA et de la CEMAC, ou un profil entièrement paramétrable ; paie en un clic, livre de paie, écritures comptables.',
    'category' => 'Ressources humaines',
    'icon' => 'payments',
    'depends' => ['rh'],
    'sort' => 61,
    'dashboard' => \App\Modules\Paie\Dashboard::class,
    'permissions' => [
        'paie.view' => 'Consulter les bulletins, le livre de paie et les déclarations',
        'paie.manage' => 'Préparer, valider et payer la paie, modifier les paramètres',
    ],
    'sync' => [],
];
