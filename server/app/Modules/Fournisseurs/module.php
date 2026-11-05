<?php

return [
    'key' => 'portail_fournisseurs',
    'name' => 'Portail fournisseurs',
    'description' => 'Espace en ligne de vos fournisseurs : appels d\'offres avec comparaison des offres et attribution en bon de commande, confirmation des commandes et des dates de livraison, dépôt des factures avec leur PDF, suivi des paiements.',
    'category' => 'Achats & stocks',
    'icon' => 'storefront',
    'depends' => ['achats'],
    'sort' => 21,
    'setup' => \App\Modules\Fournisseurs\FournisseursSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Fournisseurs\Dashboard::class,
    'permissions' => [
        'portail_fournisseurs.view' => 'Consulter les appels d\'offres, offres et factures déposées',
        'portail_fournisseurs.manage' => 'Inviter les fournisseurs, publier et attribuer les appels d\'offres, accepter les factures',
    ],
    'sync' => [
        'sp_tenders' => \App\Modules\Fournisseurs\Models\Tender::class,
        'sp_bids' => \App\Modules\Fournisseurs\Models\Bid::class,
        'sp_order_acks' => \App\Modules\Fournisseurs\Models\OrderAck::class,
        'sp_invoices' => \App\Modules\Fournisseurs\Models\SupplierInvoice::class,
    ],
];
