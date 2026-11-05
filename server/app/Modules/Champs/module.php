<?php

return [
    'key' => 'champs',
    'name' => 'Champs personnalisés',
    'description' => 'Ajoutez vos propres informations aux clients et fournisseurs, articles, devis et factures, achats, salariés, opportunités et projets : texte, nombre, montant, date, liste, cases à cocher… Obligatoires ou non, saisissables hors ligne, imprimés sur les documents et utilisables dans les rapports.',
    'category' => 'Administration',
    'icon' => 'dashboard_customize',
    'depends' => ['core'],
    'sort' => 64,
    'setup' => \App\Modules\Champs\ChampsSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'champs.manage' => 'Créer et modifier les champs personnalisés',
    ],
    'sync' => [
        'cf_fields' => \App\Modules\Champs\Models\Field::class,
    ],
];
