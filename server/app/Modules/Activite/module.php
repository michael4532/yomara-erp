<?php

return [
    'key' => 'activite',
    'name' => 'Rapports d\'activité',
    'description' => 'Rapport de chaque collaborateur rédigé automatiquement à partir de ce qu\'il a fait dans le logiciel (ventes, visites, publications, contrats, tâches, tickets, interventions, déplacements, réunions, développement…) et des activités qu\'il ajoute d\'un clic. Relecture, PDF ou Word, envoi au supérieur, validation ou demande de complément. Brouillon prêt chaque vendredi.',
    'category' => 'Collaboration',
    'icon' => 'summarize',
    'depends' => ['core'],
    'sort' => 69,
    'setup' => \App\Modules\Activite\ActiviteSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'activite.use' => 'Rédiger et envoyer ses rapports d\'activité, recevoir ceux de son équipe',
        'activite.view_all' => 'Consulter et valider tous les rapports d\'activité (direction, RH)',
    ],
    'sync' => [],
];
