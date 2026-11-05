<?php

return [
    'key' => 'materiel',
    'name' => 'Sorties de matériel',
    'description' => 'Le matériel de l\'entreprise (caméras, ordinateurs, outillage…) : qui l\'a emporté, pour quelle intervention, retour prévu ; bon de sortie PDF signé électroniquement par le porteur et le responsable du département ; retour article par article avec l\'état constaté, matériel en panne ou perdu signalé, retards en alerte — hors ligne.',
    'category' => 'Opérations',
    'icon' => 'videocam',
    'depends' => ['core'],
    'sort' => 47,
    'permissions' => [
        'materiel.use' => 'Sortir et rendre du matériel (le sien)',
        'materiel.view' => 'Consulter tout le matériel et toutes les sorties',
        'materiel.manage' => 'Gérer le matériel, enregistrer les sorties et les retours de tous',
    ],
    'sync' => [
        'mat_items' => \App\Modules\Materiel\Models\Item::class,
        'mat_checkouts' => \App\Modules\Materiel\Models\Checkout::class,
    ],
];
