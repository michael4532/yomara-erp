<?php

// Archives : conservation légale et travail de l'archiviste (plan de classement, durées de conservation,
// intégrité, prêts, éliminations avec procès-verbal).
return [
    'key' => 'archives',
    'name' => 'Archives',
    'description' => 'Archivage légal : plan de classement, archivage automatique des factures validées, durées de conservation et sort final, cotes et emplacement physique, empreinte d\'intégrité, journal des accès, prêts et consultations, éliminations avec procès-verbal.',
    'category' => 'Collaboration',
    'icon' => 'inventory_2',
    'depends' => ['core'],
    'sort' => 74,
    'provider' => \App\Modules\Archives\ArchivesProvider::class,
    'permissions' => [
        'archives.view' => 'Rechercher et consulter les archives (hors confidentielles)',
        'archives.manage' => 'Archiviste : verser, classer, coter, localiser, gérer les prêts et le plan de classement',
        'archives.confidential' => 'Consulter les archives confidentielles et secrètes',
        'archives.dispose' => 'Approuver et exécuter les éliminations',
    ],
    'sync' => [],
];
