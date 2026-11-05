<?php

return [
    'key' => 'tableaux_excel',
    'name' => 'Tableaux de bord Excel',
    'description' => 'Tableaux de bord Excel interactifs générés depuis vos données : galerie de modèles (direction, ventes, trésorerie, créances, stock, achats), thèmes de couleurs, filtres en listes déroulantes qui recalculent indicateurs, graphiques et classements, onglet de données structuré prêt pour les tableaux croisés dynamiques, envoi automatique par e-mail chaque semaine ou chaque mois.',
    'category' => 'Pilotage',
    'icon' => 'table_chart',
    'depends' => ['ventes'],
    'sort' => 12,
    'permissions' => [
        'tableaux_excel.view' => 'Générer et télécharger les tableaux de bord Excel',
        'tableaux_excel.manage' => 'Enregistrer des tableaux et programmer leur envoi par e-mail',
    ],
    'sync' => [],
];
