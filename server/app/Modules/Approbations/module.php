<?php

return [
    'key' => 'approbations',
    'name' => 'Circuits d\'approbation',
    'description' => 'Validation à plusieurs niveaux des devis, commandes, factures, bons de commande et notes de frais selon le montant, la remise, le tiers ou la catégorie : approbateurs par rôle ou par nom, « l\'un ou tous », relances, escalade, délégations pendant les absences, validation automatique après accord, historique complet.',
    'category' => 'Pilotage',
    'icon' => 'approval',
    'depends' => ['core'],
    'sort' => 63,
    'setup' => \App\Modules\Approbations\ApprobationsSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Approbations\Dashboard::class,
    'permissions' => [
        'approbations.view' => 'Voir toutes les demandes d\'approbation',
        'approbations.manage' => 'Configurer les circuits d\'approbation',
    ],
    'sync' => [
        'wf_rules' => \App\Modules\Approbations\Models\Rule::class,
        'wf_requests' => \App\Modules\Approbations\Models\ApprovalRequest::class,
    ],
];
