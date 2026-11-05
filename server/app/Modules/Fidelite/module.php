<?php

return [
    'key' => 'fidelite',
    'name' => 'Fidélité & cartes cadeaux',
    'description' => 'Programme de points (gain à chaque achat, niveaux, récompenses, expiration), paiement en points à la caisse, cartes cadeaux et bons d\'achat, espace fidélité dans le portail client.',
    'category' => 'Ventes & clients',
    'icon' => 'loyalty',
    'depends' => ['ventes'],
    'sort' => 32,
    'provider' => \App\Modules\Fidelite\FideliteModuleProvider::class,
    'dashboard' => \App\Modules\Fidelite\Dashboard::class,
    'permissions' => [
        'fidelite.view' => 'Consulter les cartes et soldes de points',
        'fidelite.manage' => 'Gérer le programme, ajuster les points, émettre des cartes cadeaux',
    ],
    'sync' => [
        'lc_cards' => \App\Modules\Fidelite\Models\LoyaltyCard::class,
        'lc_transactions' => \App\Modules\Fidelite\Models\LoyaltyTransaction::class,
        'lc_rewards' => \App\Modules\Fidelite\Models\Reward::class,
        'gift_cards' => \App\Modules\Fidelite\Models\GiftCard::class,
    ],
];
