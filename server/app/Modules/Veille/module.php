<?php

return [
    'key' => 'veille',
    'name' => 'Veille concurrentielle',
    'description' => 'Fiches concurrents complètes (offre, prix, réseaux, avis, SWOT, fiche de combat pour les commerciaux), observations du terrain avec photo, relevés de prix comparés à vos articles, publicités repérées (Meta, Google, TikTok, LinkedIn…) avec liens vers les bibliothèques publicitaires, audience des réseaux sociaux, surveillance automatique des sites concurrents avec alerte à chaque changement, analyse des sites (référencement, outils publicitaires et marketing utilisés), revue de presse et mentions sur le web, comparatif et carte de positionnement, synthèse de la semaine et analyses par l\'IA.',
    'category' => 'Marketing & communication',
    'icon' => 'radar',
    'depends' => ['core'],
    'sort' => 62,
    'setup' => \App\Modules\Veille\VeilleSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Veille\Dashboard::class,
    'permissions' => [
        'veille.view' => 'Consulter les concurrents, la veille et le comparatif',
        'veille.manage' => 'Gérer les concurrents, surveillances de sites, mots-clés et analyses',
        'veille.report' => 'Remonter des observations et relevés de prix du terrain',
    ],
    'sync' => [],
];
