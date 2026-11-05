<?php

return [
    'key' => 'compta',
    'name' => 'Comptabilité SYSCOHADA',
    'description' => 'Plan comptable OHADA, écritures automatiques, vérification des factures fournisseurs, comptes de tiers, états financiers SYSCOHADA, TVA.',
    'category' => 'Finance',
    'icon' => 'account_balance',
    'depends' => ['core'],
    'sort' => 50,
    'provider' => \App\Modules\Compta\ComptaModuleProvider::class,
    'dashboard' => \App\Modules\Compta\Dashboard::class,
    'permissions' => [
        'compta.view' => 'Consulter la comptabilité et les états',
        'compta.manage' => 'Saisir des écritures, gérer le plan comptable et les paramètres',
    ],
    'sync' => [],
];
