<?php

return [
    'key' => 'import_export',
    'name' => 'Import / export Excel',
    'description' => 'Reprise des clients, fournisseurs, articles, stocks et factures impayées depuis Excel ou CSV (correspondance des colonnes, vérification à blanc, rapport d\'erreurs), modèles à remplir, exports Excel et CSV.',
    'category' => 'Administration',
    'icon' => 'upload_file',
    'depends' => ['core'],
    'sort' => 90,
    'permissions' => [
        'import_export.import' => 'Importer des données depuis Excel / CSV',
        'import_export.export' => 'Exporter les listes en Excel / CSV',
    ],
];
