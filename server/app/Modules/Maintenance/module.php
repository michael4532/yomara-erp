<?php

return [
    'key' => 'maintenance',
    'name' => 'Maintenance',
    'description' => 'Maintenance de vos propres équipements (GMAO) : fiches machines avec compteurs, plans préventifs par date ou par compteur qui créent les ordres de travail à l\'avance, demandes d\'intervention en cas de panne par n\'importe quel salarié, ordres de travail avec technicien, liste de contrôle, temps, pièces, sous-traitance, arrêt machine et cause de panne, historique et coût par équipement, disponibilité, délai moyen de réparation.',
    'category' => 'Opérations',
    'icon' => 'build',
    'depends' => ['core'],
    'sort' => 78,
    'setup' => \App\Modules\Maintenance\MaintenanceSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Maintenance\Dashboard::class,
    'permissions' => [
        'maintenance.request' => 'Signaler une panne (demande d\'intervention)',
        'maintenance.view' => 'Consulter les équipements, plans et ordres de travail',
        'maintenance.work' => 'Exécuter les ordres de travail qui lui sont confiés (technicien)',
        'maintenance.manage' => 'Gérer les équipements, plans préventifs et ordres de travail',
    ],
    'sync' => [],
];
