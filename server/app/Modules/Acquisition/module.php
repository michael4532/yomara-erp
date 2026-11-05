<?php

return [
    'key' => 'acquisition',
    'name' => 'Publicité & acquisition',
    'description' => 'Prospects des publicités Facebook, Instagram, TikTok, LinkedIn et Google versés tout de suite dans le CRM (réponse automatique WhatsApp ou SMS, répartition entre commerciaux, relances), pages de capture, dépenses publicitaires par campagne (Meta, Google Ads, TikTok, LinkedIn ou saisie), coût par prospect, ventes et retour sur investissement, conversions renvoyées à Meta, Search Console et pixels de suivi.',
    'category' => 'Marketing & communication',
    'icon' => 'ads_click',
    'depends' => ['core', 'crm'],
    'sort' => 63,
    'provider' => \App\Modules\Acquisition\AcquisitionProvider::class,
    'setup' => \App\Modules\Acquisition\AcquisitionSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Acquisition\Dashboard::class,
    'permissions' => [
        'acquisition.view' => 'Consulter les campagnes, prospects reçus, dépenses et résultats',
        'acquisition.manage' => 'Relier les comptes publicitaires, gérer sources, pages de capture et campagnes',
    ],
    'sync' => [],
];
