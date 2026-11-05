<?php

return [
    'key' => 'billetterie',
    'name' => 'Billetterie & badges',
    'description' => 'Billets visiteurs vendus en ligne (paiement Mobile Money ou carte) et au guichet, entrées gratuites sur inscription, badges exposants, staff et presse avec QR code, contrôle d\'accès à l\'entrée (lecteur, même hors ligne), fréquentation en direct.',
    'category' => 'Métiers',
    'icon' => 'confirmation_number',
    'depends' => ['evenements'],
    'sort' => 15,
    'provider' => \App\Modules\Billetterie\BilletterieModuleProvider::class,
    'setup' => \App\Modules\Billetterie\BilletterieSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Billetterie\Dashboard::class,
    'permissions' => [
        'billetterie.scan' => 'Contrôler les billets à l\'entrée',
        'billetterie.sell' => 'Vendre des billets au guichet',
        'billetterie.manage' => 'Gérer les billets, tarifs et badges',
    ],
    'sync' => [
        'ev_ticket_types' => \App\Modules\Billetterie\Models\TicketType::class,
        'ev_ticket_orders' => \App\Modules\Billetterie\Models\TicketOrder::class,
        'ev_tickets' => \App\Modules\Billetterie\Models\Ticket::class,
        'ev_checkins' => \App\Modules\Billetterie\Models\Checkin::class,
    ],
];
