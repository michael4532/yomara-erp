<?php

return [
    'key' => 'talents',
    'name' => 'Talents : entretiens, formations, intégration',
    'description' => 'Entretiens annuels et de fin de période d\'essai (objectifs, compétences notées, auto-évaluation du salarié, avis du responsable), plan de formation (sessions, participants, heures, coût, prise en charge FDFP), parcours d\'arrivée et de départ avec cases à cocher — hors ligne.',
    'category' => 'Ressources humaines',
    'icon' => 'emoji_events',
    'depends' => ['rh'],
    'sort' => 63,
    'permissions' => [
        'talents.view' => 'Consulter tous les entretiens, formations et parcours',
        'talents.manage' => 'Organiser les entretiens, le plan de formation et les parcours d\'intégration',
    ],
    'sync' => [
        'tal_reviews' => \App\Modules\Talents\Models\Review::class,
        'tal_trainings' => \App\Modules\Talents\Models\Training::class,
        'tal_checklists' => \App\Modules\Talents\Models\Checklist::class,
    ],
];
