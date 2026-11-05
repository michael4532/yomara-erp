<?php

use App\Modules\Portail\Dashboard;

return [
    'key' => 'portail',
    'name' => 'Portail client',
    'description' => 'Espace en ligne pour vos clients, sans mot de passe : factures et paiement en ligne, devis acceptés en ligne, commandes, livraisons, relevé de compte et messages.',
    'category' => 'Ventes & clients',
    'icon' => 'web',
    'depends' => ['ventes'],
    'sort' => 45,
    'dashboard' => Dashboard::class,
    'permissions' => [
        'portail.manage' => 'Inviter les clients au portail et lire leurs messages',
    ],
];
