<?php

return [
    'key' => 'migration',
    'name' => 'Migration depuis un autre logiciel',
    'description' => 'Reprise en un clic des données d\'un autre ERP ou logiciel de gestion : connexion directe à Dolibarr, Odoo et ERPNext, ou fichiers d\'export de Sage, QuickBooks, Zoho Books, Xero, EBP, Wave, Tally, SAP Business One, Dynamics et Excel. Clients, fournisseurs, articles, stocks, factures et avoirs avec leurs lignes et leurs règlements, sans doublon si la migration est relancée.',
    'category' => 'Administration',
    'icon' => 'move_down',
    'depends' => ['ventes'],
    'sort' => 91,
    'permissions' => [
        'migration.run' => 'Migrer les données d\'un autre logiciel',
    ],
    'sync' => [],
];
