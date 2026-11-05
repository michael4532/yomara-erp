<?php

return [
    'key' => 'btp',
    'name' => 'BTP & chantiers',
    'description' => 'Chantiers de construction et de rénovation : devis quantitatif par lots et ouvrages avec déboursé sec et marge, marché signé, avance de démarrage, situations de travaux à l\'avancement avec retenue de garantie et remboursement de l\'avance, décomptes PDF, journal de chantier, coûts réels (achats, main-d\'œuvre, frais) face au budget, marge prévue et réelle.',
    'category' => 'Métiers',
    'icon' => 'construction',
    'depends' => ['projets', 'ventes'],
    'sort' => 61,
    'permissions' => [
        'btp.view' => 'Consulter les chantiers, devis, situations et le journal',
        'btp.manage' => 'Créer les chantiers, chiffrer les devis, établir les situations et facturer',
        'btp.log' => 'Tenir le journal de chantier (chef de chantier)',
    ],
    'sync' => [],
];
