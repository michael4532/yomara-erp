<?php

return [
    'key' => 'abonnements',
    'name' => 'Contrats & Abonnements',
    'description' => 'Factures récurrentes : abonnements, loyers, maintenance, licences… facturés chaque semaine, mois, trimestre ou année, validés et envoyés automatiquement avec lien de paiement, rattrapage des échéances, revenu récurrent mensuel.',
    'category' => 'Ventes & clients',
    'icon' => 'autorenew',
    'depends' => ['ventes'],
    'sort' => 14,
    'setup' => \App\Modules\Abonnements\AbonnementsSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Abonnements\Dashboard::class,
    'permissions' => [
        'abonnements.view' => 'Consulter les contrats d\'abonnement',
        'abonnements.manage' => 'Gérer les contrats et générer les factures',
    ],
    'sync' => [
        'ab_subscriptions' => \App\Modules\Abonnements\Models\Subscription::class,
    ],
];
