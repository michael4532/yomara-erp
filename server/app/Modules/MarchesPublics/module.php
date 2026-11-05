<?php

return [
    'key' => 'marches_publics',
    'name' => 'Appels d\'offres & marchés',
    'description' => 'Veille et suivi des appels d\'offres publics et privés (date de dépôt, décision de soumissionner, compte à rebours), dossier de soumission avec la liste des pièces à réunir et leurs responsables, pièces administratives permanentes (attestations fiscale, CNPS, RCCM…) avec alertes avant expiration, cautions et garanties bancaires (soumission, avance, bonne exécution, retenue) jusqu\'à la mainlevée, marchés attribués, décomptes avec retenue de garantie et remboursement de l\'avance, facturation, réceptions provisoire et définitive, taux de réussite.',
    'category' => 'Métiers',
    'icon' => 'gavel',
    'depends' => ['core'],
    'sort' => 76,
    'setup' => \App\Modules\MarchesPublics\MarchesSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\MarchesPublics\Dashboard::class,
    'permissions' => [
        'marches.view' => 'Consulter les appels d\'offres, marchés et cautions',
        'marches.manage' => 'Préparer les soumissions, gérer les marchés, décomptes et cautions',
    ],
    'sync' => [],
];
