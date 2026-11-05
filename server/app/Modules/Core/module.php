<?php

return [
    'key' => 'core',
    'name' => 'Base',
    'description' => 'Société, utilisateurs, rôles, contacts, produits, paramètres.',
    'category' => 'Socle',
    'icon' => 'apps',
    'is_core' => true,
    'depends' => [],
    'sort' => 0,
    'setup' => \App\Modules\Core\CoreSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Core\Dashboard::class,
    'permissions' => [
        'core.contacts.view' => 'Voir les contacts',
        'core.contacts.manage' => 'Créer / modifier les contacts',
        'core.contacts.delete' => 'Supprimer des contacts',
        'core.products.view' => 'Voir les produits',
        'core.products.manage' => 'Créer / modifier les produits',
        'core.products.delete' => 'Supprimer des produits',
        'core.users.manage' => 'Gérer les utilisateurs et rôles',
        'core.settings.manage' => 'Modifier les paramètres de la société',
    ],
    'sync' => [
        'partners' => \App\Modules\Core\Models\Partner::class,
        'product_categories' => \App\Modules\Core\Models\ProductCategory::class,
        'products' => \App\Modules\Core\Models\Product::class,
        'payment_terms' => \App\Modules\Core\Models\PaymentTerm::class,
    ],
];
