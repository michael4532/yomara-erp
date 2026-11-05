<?php

return [
    'key' => 'helpdesk',
    'name' => 'Service client',
    'description' => 'Tickets des clients (téléphone, WhatsApp, e-mail, formulaire en ligne, espace client) avec délais garantis (SLA) en heures ouvrées, attribution automatique, réponses types, notes internes, temps passé décompté des forfaits, satisfaction, centre d\'aide public avec base de connaissances, et réception des e-mails (boîte IMAP ou webhook) et des messages WhatsApp avec leurs pièces jointes.',
    'category' => 'Opérations',
    'icon' => 'support_agent',
    'depends' => ['core'],
    'sort' => 48,
    'setup' => \App\Modules\Helpdesk\HelpdeskSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Helpdesk\Dashboard::class,
    'permissions' => [
        'helpdesk.view' => 'Consulter les tickets du service client',
        'helpdesk.agent' => 'Traiter les tickets : répondre, notes, temps passé',
        'helpdesk.manage' => 'Régler les délais (SLA), catégories, réponses types et la base de connaissances',
    ],
    'sync' => [
        'hd_sla_policies' => \App\Modules\Helpdesk\Models\SlaPolicy::class,
        'hd_categories' => \App\Modules\Helpdesk\Models\Category::class,
        'hd_tickets' => \App\Modules\Helpdesk\Models\Ticket::class,
        'hd_messages' => \App\Modules\Helpdesk\Models\Message::class,
        'hd_times' => \App\Modules\Helpdesk\Models\TimeEntry::class,
        'hd_articles' => \App\Modules\Helpdesk\Models\Article::class,
        'hd_macros' => \App\Modules\Helpdesk\Models\Macro::class,
        'hd_attachments' => \App\Modules\Helpdesk\Models\Attachment::class,
    ],
];
