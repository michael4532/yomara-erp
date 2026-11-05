<?php

return [
    'key' => 'rendez_vous',
    'name' => 'Prise de rendez-vous',
    'description' => 'Page de réservation en ligne, prestations, horaires de l\'équipe, agenda, confirmations et rappels par WhatsApp, SMS ou e-mail, annulation par le client, facturation.',
    'category' => 'Ventes & clients',
    'icon' => 'event_available',
    'depends' => ['core'],
    'sort' => 46,
    'provider' => \App\Modules\RendezVous\RendezVousModuleProvider::class,
    'dashboard' => \App\Modules\RendezVous\Dashboard::class,
    'permissions' => [
        'rendez_vous.view' => 'Consulter l\'agenda de toute l\'équipe',
        'rendez_vous.own' => 'Gérer ses propres rendez-vous',
        'rendez_vous.manage' => 'Gérer l\'agenda, les prestations, les horaires et la page de réservation',
    ],
    'sync' => [
        'rdv_services' => \App\Modules\RendezVous\Models\Service::class,
        'rdv_staff' => \App\Modules\RendezVous\Models\Staff::class,
        'rdv_closures' => \App\Modules\RendezVous\Models\Closure::class,
        'rdv_appointments' => \App\Modules\RendezVous\Models\Appointment::class,
    ],
];
