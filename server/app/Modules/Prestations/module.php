<?php

return [
    'key' => 'prestations',
    'name' => 'Forfaits & livrables',
    'description' => 'Pour les prestataires de services : forfaits d\'heures prépayés (maintenance, support, community management…), temps consommé saisi par l\'équipe, alertes à 80 % et 100 %, dépassement facturé, renouvellement ; livrables envoyés au client (fichier ou lien), validation ou demande de modifications en ligne, versions et historique, le tout visible sur l\'espace client ; rapport d\'activité mensuel en PDF envoyé à chaque client ; temps des projets décomptés du forfait.',
    'category' => 'Opérations',
    'icon' => 'work_history',
    'depends' => ['ventes'],
    'sort' => 47,
    'setup' => \App\Modules\Prestations\PrestationsSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Prestations\Dashboard::class,
    'permissions' => [
        'prestations.view' => 'Consulter les forfaits et livrables',
        'prestations.log' => 'Saisir du temps sur les forfaits et préparer des livrables',
        'prestations.manage' => 'Gérer les forfaits, la facturation et l\'envoi des livrables',
    ],
    'sync' => [
        'pr_packs' => \App\Modules\Prestations\Models\Pack::class,
        'pr_entries' => \App\Modules\Prestations\Models\Entry::class,
        'pr_deliverables' => \App\Modules\Prestations\Models\Deliverable::class,
        'pr_reports' => \App\Modules\Prestations\Models\Report::class,
    ],
];
