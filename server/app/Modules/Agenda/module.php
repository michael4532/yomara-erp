<?php

return [
    'key' => 'agenda',
    'name' => 'Agenda',
    'description' => 'Agenda partagé : réunions et rendez-vous avec invitations et réponses, récurrence, rappels, salles et véhicules sans double réservation, vue d\'équipe, congés, entretiens, interventions et échéances des autres modules, abonnement depuis Google Agenda ou Outlook.',
    'category' => 'Collaboration',
    'icon' => 'calendar_month',
    'depends' => ['core'],
    'sort' => 70,
    'setup' => \App\Modules\Agenda\AgendaSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Agenda\Dashboard::class,
    'permissions' => [
        'agenda.use' => 'Utiliser l\'agenda (ses événements, invitations, agenda de l\'équipe)',
        'agenda.manage' => 'Gérer les ressources (salles, véhicules) et modifier les événements des autres',
    ],
    'sync' => [
        'ag_events' => \App\Modules\Agenda\Models\Event::class,
        'ag_resources' => \App\Modules\Agenda\Models\Resource::class,
    ],
];
