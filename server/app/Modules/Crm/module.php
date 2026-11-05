<?php

return [
    'key' => 'crm',
    'name' => 'CRM',
    'description' => 'Prospects et opportunités, pipeline par étapes, activités et relances, devis, prévisions de ventes.',
    'category' => 'Ventes & clients',
    'icon' => 'handshake',
    'depends' => ['core'],
    'sort' => 15,
    'provider' => \App\Modules\Crm\CrmModuleProvider::class,
    'dashboard' => \App\Modules\Crm\Dashboard::class,
    'setup' => \App\Modules\Crm\CrmSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'crm.view' => 'Consulter les opportunités et activités',
        'crm.manage' => 'Créer et modifier les opportunités, activités et étapes',
    ],
    'sync' => [
        'crm_stages' => \App\Modules\Crm\Models\Stage::class,
        'crm_leads' => \App\Modules\Crm\Models\Lead::class,
        'crm_activities' => \App\Modules\Crm\Models\Activity::class,
    ],
];
