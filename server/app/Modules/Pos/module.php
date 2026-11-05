<?php

return [
    'key' => 'pos',
    'name' => 'Point de vente (Caisse)',
    'description' => 'Caisse tactile hors ligne, tickets, sessions de caisse, Mobile Money.',
    'category' => 'Caisse & restaurant',
    'icon' => 'point_of_sale',
    'depends' => ['ventes', 'stock'],
    'sort' => 40,
    'provider' => \App\Modules\Pos\PosModuleProvider::class,
    'dashboard' => \App\Modules\Pos\Dashboard::class,
    'permissions' => [
        'pos.use' => 'Utiliser la caisse (vendre, ouvrir / clôturer une session)',
        'pos.manage' => 'Gérer les caisses et voir tous les rapports',
    ],
    'sync' => [
        'pos_registers' => \App\Modules\Pos\Models\PosRegister::class,
        'pos_sessions' => \App\Modules\Pos\Models\PosSession::class,
    ],
];
