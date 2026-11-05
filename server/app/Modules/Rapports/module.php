<?php

return [
    'key' => 'rapports',
    'name' => 'Rapports & tableaux de bord',
    'description' => 'Générateur de rapports sur toutes les données (ventes, marges, achats, trésorerie, comptabilité, stock, paie, CRM, projets) : regroupements, filtres, périodes relatives, comparaison, graphiques et tableaux croisés ; tableaux de bord personnalisés partagés par rôle ; exports Excel et PDF ; envois programmés par e-mail.',
    'category' => 'Pilotage',
    'icon' => 'insights',
    'depends' => ['core'],
    'sort' => 3,
    'setup' => \App\Modules\Rapports\RapportsSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'rapports.view' => 'Consulter les rapports et tableaux de bord partagés',
        'rapports.create' => 'Créer ses propres rapports et tableaux de bord',
        'rapports.manage' => 'Partager, modifier les rapports partagés et programmer les envois',
    ],
    'sync' => [
        'rp_reports' => \App\Modules\Rapports\Models\Report::class,
        'rp_dashboards' => \App\Modules\Rapports\Models\Board::class,
    ],
];
