<?php

use App\Modules\Qualite\QualiteHooks as H;

$staff = 'qualite.view|qualite.inspect|qualite.manage';
$manage = 'qualite.manage';

return [
    'hooks' => H::class,
    'resources' => [
        'nonconformities' => [
            'table' => 'qa_nonconformities', 'label' => 'Non-conformité', 'plural' => 'Non-conformités & réclamations', 'icon' => 'report',
            'view' => 'qualite.report|'.$staff, 'manage' => 'qualite.report|qualite.inspect|qualite.manage', 'delete' => $manage,
            'order' => 'id desc', 'date_field' => 'detected_on', 'search' => ['number', 'title', 'product', 'description', 'root_cause'],
            'scope' => fn ($q, $u) => $u->hasPermission('qualite.view') || $u->hasPermission('qualite.manage') || $u->hasPermission('qualite.inspect')
                ? $q : $q->where(fn ($w) => $w->where('reporter_id', $u->id)->orWhere('owner_id', $u->id)),
            'hint' => 'Ouverte → analyse des causes → actions → vérification de l\'efficacité → clôturée.',
            'fields' => [
                ['key' => 'title', 'label' => 'Problème', 'hint' => 'Problème constaté', 'required' => true, 'list' => true],
                ['key' => 'number', 'label' => 'N°', 'readonly' => true, 'list' => true, 'half' => true],
                ['key' => 'status', 'label' => 'Étape', 'type' => 'select', 'default' => 'open', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::NC_STATUSES],
                ['key' => 'source', 'label' => 'Origine', 'type' => 'select', 'default' => 'internal', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::SOURCES],
                ['key' => 'severity', 'label' => 'Gravité', 'type' => 'select', 'default' => 'minor', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::SEVERITIES],
                ['key' => 'detected_on', 'label' => 'Constatée le', 'type' => 'date', 'default' => '@today', 'list' => true, 'half' => true],
                ['key' => 'reporter_id', 'label' => 'Signalée par', 'type' => 'user', 'default' => '@me', 'half' => true],
                ['key' => 'partner_id', 'label' => 'Client / fournisseur concerné', 'type' => 'partner', 'half' => true],
                ['key' => 'product', 'label' => 'Produit, lot ou service concerné', 'half' => true],
                ['key' => 'owner_id', 'label' => 'Responsable', 'hint' => 'Responsable du traitement', 'type' => 'user', 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'due_date', 'label' => 'À traiter avant le', 'type' => 'date', 'half' => true],
                ['key' => 'alert', 'label' => 'Suivi', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'description', 'label' => 'Description', 'type' => 'textarea'],
                ['key' => 'containment', 'label' => 'Action immédiate (mise en quarantaine, tri, geste commercial…)', 'type' => 'textarea'],
                ['key' => 'cause_category', 'label' => 'Famille de cause', 'type' => 'select', 'half' => true, 'filter' => true, 'options' => H::CAUSES],
                ['key' => 'cost', 'label' => 'Coût de non-qualité', 'type' => 'money', 'half' => true],
                ['key' => 'root_cause', 'label' => 'Analyse des causes (les « 5 pourquoi »)', 'type' => 'textarea'],
                ['key' => 'effective', 'label' => 'Actions efficaces (problème disparu)', 'type' => 'bool', 'half' => true],
                ['key' => 'closed_on', 'label' => 'Clôturée le', 'type' => 'date', 'readonly' => true, 'half' => true],
            ],
        ],
        'actions' => [
            'table' => 'qa_actions', 'label' => 'Action', 'plural' => 'Actions correctives & préventives', 'icon' => 'task_alt',
            'view' => $staff, 'manage' => 'qualite.inspect|qualite.manage', 'order' => 'status asc,due_date asc', 'date_field' => 'due_date', 'search' => ['title', 'result'],
            'hint' => 'Le responsable est prévenu à l\'échéance, puis chaque semaine tant que l\'action n\'est pas faite.',
            'fields' => [
                ['key' => 'title', 'label' => 'Action', 'required' => true, 'list' => true],
                ['key' => 'nc_id', 'label' => 'Non-conformité', 'type' => 'ref', 'resource' => 'nonconformities', 'list' => true, 'filter' => true],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'default' => 'corrective', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::ACTION_KINDS],
                ['key' => 'status', 'label' => 'État', 'type' => 'select', 'default' => 'todo', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::ACTION_STATUSES],
                ['key' => 'owner_id', 'label' => 'Responsable', 'type' => 'user', 'default' => '@me', 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'due_date', 'label' => 'Échéance', 'type' => 'date', 'list' => true, 'half' => true],
                ['key' => 'alert', 'label' => 'Alerte', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'done_on', 'label' => 'Réalisée le', 'type' => 'date', 'half' => true],
                ['key' => 'result', 'label' => 'Ce qui a été fait / résultat', 'type' => 'textarea'],
            ],
        ],
        'inspections' => [
            'table' => 'qa_inspections', 'label' => 'Contrôle', 'plural' => 'Contrôles réalisés', 'icon' => 'fact_check',
            'view' => $staff, 'manage' => 'qualite.inspect|qualite.manage', 'delete' => $manage, 'order' => 'done_at desc', 'date_field' => 'done_at', 'search' => ['title', 'subject', 'notes'],
            'hint' => 'Choisissez le plan, puis « Remplir le contrôle » : critère par critère, conforme ou non.',
            'fields' => [
                ['key' => 'plan_id', 'label' => 'Plan de contrôle', 'type' => 'ref', 'resource' => 'plans', 'required' => true, 'list' => true, 'filter' => true],
                ['key' => 'done_at', 'label' => 'Réalisé le', 'type' => 'datetime', 'default' => '@now', 'list' => true, 'half' => true],
                ['key' => 'owner_id', 'label' => 'Contrôleur', 'type' => 'user', 'default' => '@me', 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'subject', 'label' => 'Objet contrôlé', 'hint' => 'Lot, produit, zone, livraison…', 'list' => true],
                ['key' => 'partner_id', 'label' => 'Fournisseur / client', 'type' => 'partner', 'half' => true],
                ['key' => 'result', 'label' => 'Résultat', 'type' => 'select', 'default' => 'pending', 'readonly' => true, 'list' => true, 'half' => true, 'filter' => true, 'options' => H::RESULTS],
                ['key' => 'score', 'label' => 'Conformité (%)', 'type' => 'number', 'readonly' => true, 'list' => true, 'half' => true],
                ['key' => 'nc_id', 'label' => 'Non-conformité ouverte', 'type' => 'ref', 'resource' => 'nonconformities', 'readonly' => true, 'half' => true],
                ['key' => 'notes', 'label' => 'Observations', 'type' => 'textarea'],
            ],
        ],
        'plans' => [
            'table' => 'qa_plans', 'label' => 'Plan de contrôle', 'plural' => 'Plans de contrôle', 'icon' => 'checklist',
            'view' => $staff, 'manage' => $manage, 'order' => 'title asc', 'search' => ['title', 'criteria'],
            'hint' => 'Les points à vérifier, un par ligne. Un contrôle sous le seuil est non conforme et ouvre une non-conformité.',
            'fields' => [
                ['key' => 'title', 'label' => 'Plan de contrôle', 'required' => true, 'list' => true, 'hint' => 'Ex. Réception des matières premières'],
                ['key' => 'scope', 'label' => 'Domaine', 'type' => 'select', 'default' => 'reception', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::SCOPES],
                ['key' => 'frequency', 'label' => 'Fréquence', 'type' => 'select', 'default' => 'each', 'list' => true, 'half' => true, 'options' => H::FREQUENCIES],
                ['key' => 'owner_id', 'label' => 'Responsable', 'type' => 'user', 'list' => true, 'half' => true],
                ['key' => 'threshold', 'label' => 'Seuil de conformité (%)', 'type' => 'number', 'default' => 100, 'half' => true],
                ['key' => 'auto_nc', 'label' => 'Ouvrir une non-conformité si non conforme', 'type' => 'bool', 'default' => true, 'half' => true],
                ['key' => 'active', 'label' => 'Actif', 'type' => 'bool', 'default' => true, 'list' => true, 'half' => true],
                ['key' => 'stats', 'label' => '30 derniers jours', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'criteria', 'label' => 'Critères à vérifier (un par ligne)', 'type' => 'textarea', 'required' => true],
            ],
        ],
    ],
];
