<?php

return [
    'key' => 'tresorerie',
    'name' => 'Banque & Trésorerie',
    'description' => 'Comptes bancaires, caisses et Mobile Money, soldes en temps réel, dépenses et recettes diverses, virements internes, import des relevés et rapprochement automatique, prévision de trésorerie.',
    'category' => 'Finance',
    'icon' => 'savings',
    'depends' => ['core'],
    'sort' => 62,
    'provider' => \App\Modules\Tresorerie\TresorerieModuleProvider::class,
    'setup' => \App\Modules\Tresorerie\TresorerieSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Tresorerie\Dashboard::class,
    'permissions' => [
        'tresorerie.view' => 'Consulter les comptes, soldes et prévisions',
        'tresorerie.operate' => 'Saisir les dépenses et recettes diverses',
        'tresorerie.manage' => 'Gérer les comptes, importer les relevés et rapprocher',
    ],
    'sync' => [
        'tr_accounts' => \App\Modules\Tresorerie\Models\TreasuryAccount::class,
        'tr_operations' => \App\Modules\Tresorerie\Models\Operation::class,
        'tr_forecasts' => \App\Modules\Tresorerie\Models\Forecast::class,
    ],
];
