<?php

return [
    'key' => 'ventes',
    'name' => 'Ventes & Facturation',
    'description' => 'Devis, commandes, factures, avoirs, encaissements.',
    'category' => 'Ventes & clients',
    'icon' => 'receipt',
    'depends' => ['core'],
    'sort' => 10,
    'dashboard' => \App\Modules\Ventes\Dashboard::class,
    'permissions' => [
        'ventes.view' => 'Voir les ventes',
        'ventes.manage' => 'Créer et modifier des devis, commandes, factures',
        'ventes.validate' => 'Valider les devis, commandes et factures',
        'ventes.delete' => 'Supprimer ou annuler des documents de vente',
        'ventes.payments.manage' => 'Enregistrer des encaissements',
    ],
    'sync' => [
        'sales_documents' => \App\Modules\Ventes\Models\SalesDocument::class,
        'sales_payments' => \App\Modules\Ventes\Models\SalesPayment::class,
    ],
];
