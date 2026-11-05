<?php

return [
    'key' => 'fne',
    'name' => 'Facture normalisée (FNE)',
    'description' => 'Certification électronique des factures, tickets de caisse et avoirs auprès de la DGI de Côte d\'Ivoire : envoi automatique à la validation, QR code de vérification sur chaque facture, file d\'attente hors ligne, suivi du stock de stickers.',
    'category' => 'Ventes & clients',
    'icon' => 'verified',
    'depends' => ['ventes'],
    'sort' => 12,
    'setup' => \App\Modules\Fne\FneSetup::class,
    'setup_version' => 1,
    'provider' => \App\Modules\Fne\FneModuleProvider::class,
    'dashboard' => \App\Modules\Fne\Dashboard::class,
    'permissions' => [
        'fne.view' => 'Consulter les certifications FNE',
        'fne.manage' => 'Paramétrer la connexion DGI et certifier manuellement',
    ],
    'sync' => [
        'fne_certifications' => \App\Modules\Fne\Models\FneCertification::class,
    ],
];
