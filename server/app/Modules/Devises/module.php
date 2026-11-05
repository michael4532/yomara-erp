<?php

return [
    'key' => 'devises',
    'name' => 'Multi-devises',
    'description' => 'Devis, factures, achats et paiements en euros, dollars ou toute autre devise : cours du jour, parité fixe franc CFA / euro, contrevaleur en francs CFA, écarts de change comptabilisés.',
    'category' => 'Finance',
    'icon' => 'currency_exchange',
    'depends' => ['core'],
    'sort' => 54,
    'setup' => \App\Modules\Devises\DevisesSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'devises.manage' => 'Gérer les devises et les cours de change',
    ],
    'sync' => [
        'fx_rates' => \App\Modules\Devises\Models\FxRate::class,
    ],
];
