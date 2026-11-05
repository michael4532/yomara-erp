<?php

return [
    'key' => 'relances',
    'name' => 'Relances & recouvrement',
    'description' => 'Relances automatiques des factures impayées par paliers (rappel, relance, dernier avis) par e-mail, WhatsApp ou SMS avec lien de paiement, balance âgée, historique, client suspendu ; dossiers de recouvrement (promesses, échéancier négocié, mise en demeure, huissier, passage en perte) et tableau de bord.',
    'category' => 'Ventes & clients',
    'icon' => 'notifications_active',
    'depends' => ['ventes'],
    'sort' => 13,
    'setup' => \App\Modules\Relances\RelancesSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Relances\Dashboard::class,
    'permissions' => [
        'relances.view' => 'Consulter la balance âgée et les relances',
        'relances.manage' => 'Paramétrer et envoyer les relances',
    ],
];
