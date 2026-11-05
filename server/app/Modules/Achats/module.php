<?php

return [
    'key' => 'achats',
    'name' => 'Achats & Fournisseurs',
    'description' => 'Demandes d\'achat internes avec approbation, demandes de prix, bons de commande (seuil d\'approbation), réceptions partielles, tarifs fournisseurs, réapprovisionnement, factures et paiements fournisseurs.',
    'category' => 'Achats & stocks',
    'icon' => 'shopping_cart',
    'depends' => ['core'],
    'sort' => 20,
    'provider' => \App\Modules\Achats\AchatsModuleProvider::class,
    'dashboard' => \App\Modules\Achats\Dashboard::class,
    'permissions' => [
        'achats.view' => 'Voir les achats',
        'achats.manage' => 'Gérer les achats et paiements fournisseurs',
        'achats.delete' => 'Supprimer des documents d\'achat',
        'achats.request' => 'Faire des demandes d\'achat',
        'achats.approve' => 'Approuver les demandes d\'achat et les bons de commande au-delà du seuil',
        'achats.receive' => 'Réceptionner les marchandises',
    ],
    'sync' => [
        'purchase_documents' => \App\Modules\Achats\Models\PurchaseDocument::class,
        'purchase_payments' => \App\Modules\Achats\Models\PurchasePayment::class,
        'purchase_requests' => \App\Modules\Achats\Models\PurchaseRequest::class,
        'purchase_receipts' => \App\Modules\Achats\Models\PurchaseReceipt::class,
        'supplier_prices' => \App\Modules\Achats\Models\SupplierPrice::class,
    ],
];
