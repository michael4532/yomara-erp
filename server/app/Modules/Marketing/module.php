<?php

return [
    'key' => 'marketing',
    'name' => 'Marketing & e-mailing',
    'description' => 'Campagnes e-mail, SMS et WhatsApp, propositions commerciales, éditeur de modèles (blocs + HTML), listes de contacts, statistiques.',
    'category' => 'Marketing & communication',
    'icon' => 'campaign',
    'depends' => ['core'],
    'sort' => 80,
    'provider' => \App\Modules\Marketing\MarketingProvider::class,
    'dashboard' => \App\Modules\Marketing\Dashboard::class,
    'setup' => \App\Modules\Marketing\MarketingSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'marketing.view' => 'Consulter les campagnes et statistiques',
        'marketing.manage' => 'Créer des listes, modèles et campagnes, et les envoyer',
    ],
    'sync' => [],
];
