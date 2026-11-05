<?php

use App\Modules\Commercial\CommercialHooks as H;

$own = fn ($q, $u) => $u->hasPermission('commercial.manage') || $u->hasPermission('commercial.view') ? $q : $q->where('user_id', $u->id);
$seller = ['key' => 'user_id', 'label' => 'Commercial', 'type' => 'user', 'default' => '@me', 'required' => true, 'list' => true, 'half' => true, 'filter' => true];

return [
    'hooks' => H::class,
    'resources' => [
        'visits' => [
            'table' => 'cm_visits', 'label' => 'Visite', 'plural' => 'Visites & comptes rendus', 'icon' => 'storefront',
            'view' => 'commercial.view|commercial.field', 'manage' => 'commercial.field|commercial.manage', 'delete' => 'commercial.manage',
            'order' => 'planned_at desc,id desc', 'date_field' => 'planned_at', 'search' => ['title', 'prospect', 'report', 'contact'], 'scope' => $own,
            'hint' => 'Planifiez la visite, puis remplissez le compte rendu : résultat, montant en jeu, prochaine étape (une relance est créée dans le CRM).',
            'fields' => [
                ['key' => 'title', 'label' => 'Objet', 'required' => true, 'list' => true, 'hint' => 'Ex. Présentation de l\'offre Wi-Fi'],
                ['key' => 'client', 'label' => 'Client / prospect', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'partner_id', 'label' => 'Client', 'type' => 'partner', 'half' => true],
                ['key' => 'prospect', 'label' => 'Ou prospect (nom)', 'half' => true, 'hint' => 'S\'il n\'est pas encore dans vos contacts'],
                ['key' => 'contact', 'label' => 'Personne rencontrée', 'half' => true],
                ['key' => 'address', 'label' => 'Lieu', 'half' => true],
                $seller,
                ['key' => 'planned_at', 'label' => 'Date prévue', 'type' => 'datetime', 'required' => true, 'list' => true, 'half' => true],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'default' => 'visit', 'half' => true, 'filter' => true, 'options' => H::KINDS],
                ['key' => 'tour_id', 'label' => 'Tournée', 'type' => 'ref', 'resource' => 'tours', 'half' => true, 'filter' => true],
                ['key' => 'status', 'label' => 'Statut', 'type' => 'select', 'default' => 'planned', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::VISIT_STATUSES],
                ['key' => 'outcome', 'label' => 'Résultat', 'type' => 'select', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::OUTCOMES],
                ['key' => 'amount', 'label' => 'Montant en jeu', 'type' => 'money', 'list' => true, 'half' => true],
                ['key' => 'report', 'label' => 'Compte rendu', 'type' => 'textarea', 'hint' => 'Besoins exprimés, objections, concurrents cités, informations utiles'],
                ['key' => 'next_step', 'label' => 'Prochaine étape', 'half' => true],
                ['key' => 'next_date', 'label' => 'À faire le', 'type' => 'date', 'half' => true],
            ],
        ],
        'tours' => [
            'table' => 'cm_tours', 'label' => 'Tournée', 'plural' => 'Tournées', 'icon' => 'route',
            'view' => 'commercial.view|commercial.field', 'manage' => 'commercial.field|commercial.manage', 'delete' => 'commercial.manage',
            'order' => 'date desc,id desc', 'date_field' => 'date', 'search' => ['title', 'zone'], 'scope' => $own,
            'hint' => 'Une journée de terrain : visites prévues, véhicule demandé au parc automobile, bilan.',
            'fields' => [
                ['key' => 'title', 'label' => 'Tournée', 'required' => true, 'list' => true, 'hint' => 'Ex. Zone industrielle de Yopougon'],
                ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'default' => '@today', 'list' => true, 'half' => true],
                $seller,
                ['key' => 'zone', 'label' => 'Zone / quartiers', 'list' => true, 'half' => true],
                ['key' => 'status', 'label' => 'Statut', 'type' => 'select', 'default' => 'planned', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::TOUR_STATUSES],
                ['key' => 'visits', 'label' => 'Visites', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'vehicle', 'label' => 'Véhicule', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'notes', 'label' => 'Préparation', 'type' => 'textarea'],
                ['key' => 'summary', 'label' => 'Bilan de la journée', 'type' => 'textarea'],
            ],
        ],
        'targets' => [
            'table' => 'cm_targets', 'label' => 'Objectif', 'plural' => 'Objectifs', 'icon' => 'flag',
            'view' => 'commercial.view|commercial.field', 'manage' => 'commercial.manage', 'order' => 'period_start desc,id desc', 'date_field' => 'period_start', 'search' => ['title'], 'scope' => $own,
            'hint' => 'Le réalisé est calculé : chiffre d\'affaires des factures validées du commercial, visites faites, nouveaux clients, devis.',
            'fields' => [
                ['key' => 'title', 'label' => 'Objectif', 'readonly' => true, 'list' => true],
                ['key' => 'user_id', 'label' => 'Commercial', 'type' => 'user', 'required' => true, 'half' => true, 'filter' => true],
                ['key' => 'period_kind', 'label' => 'Période', 'type' => 'select', 'default' => 'month', 'half' => true, 'options' => H::PERIODS],
                ['key' => 'period_start', 'label' => 'À partir du', 'type' => 'date', 'required' => true, 'half' => true],
                ['key' => 'revenue_target', 'label' => 'Chiffre d\'affaires visé (HT)', 'type' => 'money', 'half' => true],
                ['key' => 'revenue_done', 'label' => 'CA réalisé', 'type' => 'money', 'computed' => true, 'list' => true, 'half' => true],
                ['key' => 'progress', 'label' => 'Atteinte', 'type' => 'text', 'computed' => true, 'list' => true, 'half' => true],
                ['key' => 'visits_target', 'label' => 'Visites visées', 'type' => 'number', 'half' => true],
                ['key' => 'customers_target', 'label' => 'Nouveaux clients visés', 'type' => 'number', 'half' => true],
                ['key' => 'quotes_target', 'label' => 'Devis visés', 'type' => 'number', 'half' => true],
                ['key' => 'activity', 'label' => 'Visites · clients · devis', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'notes', 'label' => 'Remarques', 'type' => 'textarea'],
            ],
        ],
    ],
];
