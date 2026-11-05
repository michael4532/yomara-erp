<?php

use App\Modules\Dev\DevHooks as H;

$view = 'dev.view|dev.contribute';

return [
    'hooks' => H::class,
    'resources' => [
        'items' => [
            'table' => 'dev_items', 'label' => 'Tâche', 'plural' => 'Backlog & tâches', 'icon' => 'bug_report',
            'view' => $view, 'manage' => 'dev.contribute|dev.manage', 'delete' => 'dev.manage', 'order' => 'id desc', 'search' => ['title', 'number', 'description', 'branch'],
            'hint' => 'Récits utilisateur, bugs, tâches techniques : backlog → à faire → en cours → revue de code → recette → terminé.',
            'fields' => [
                ['key' => 'title', 'label' => 'Intitulé', 'required' => true, 'list' => true],
                ['key' => 'number', 'label' => 'N°', 'readonly' => true, 'list' => true],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'default' => 'story', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::KINDS],
                ['key' => 'status', 'label' => 'Statut', 'type' => 'select', 'default' => 'backlog', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::STATUSES],
                ['key' => 'priority', 'label' => 'Priorité', 'type' => 'select', 'default' => 'normal', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::PRIORITIES],
                ['key' => 'points', 'label' => 'Points (estimation)', 'type' => 'number', 'half' => true],
                ['key' => 'app_id', 'label' => 'Application', 'type' => 'ref', 'resource' => 'apps', 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'sprint_id', 'label' => 'Sprint', 'type' => 'ref', 'resource' => 'sprints', 'half' => true, 'filter' => true],
                ['key' => 'assignee_id', 'label' => 'Assigné à', 'type' => 'user', 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'reporter_id', 'label' => 'Demandé par', 'type' => 'user', 'default' => '@me', 'half' => true],
                ['key' => 'due_date', 'label' => 'Échéance', 'type' => 'date', 'half' => true],
                ['key' => 'release_id', 'label' => 'Version', 'type' => 'ref', 'resource' => 'releases', 'half' => true, 'filter' => true],
                ['key' => 'branch', 'label' => 'Branche Git', 'half' => true],
                ['key' => 'pr_url', 'label' => 'Pull / merge request', 'type' => 'url', 'half' => true],
                ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'hint' => 'Pour un bug : étapes pour reproduire, résultat attendu, résultat obtenu'],
                ['key' => 'acceptance', 'label' => 'Critères d\'acceptation', 'type' => 'textarea'],
                ['key' => 'done_at', 'label' => 'Terminé le', 'type' => 'datetime', 'readonly' => true, 'half' => true],
                ['key' => 'git_activity', 'label' => 'Activité Git', 'type' => 'textarea', 'computed' => true],
            ],
        ],
        'sprints' => [
            'table' => 'dev_sprints', 'label' => 'Sprint', 'plural' => 'Sprints', 'icon' => 'directions_run',
            'view' => $view, 'manage' => 'dev.manage', 'order' => 'start_date desc,id desc', 'date_field' => 'start_date', 'search' => ['title', 'goal'],
            'fields' => [
                ['key' => 'title', 'label' => 'Sprint', 'required' => true, 'list' => true, 'hint' => 'Ex. Sprint 12'],
                ['key' => 'app_id', 'label' => 'Application', 'type' => 'ref', 'resource' => 'apps', 'list' => true, 'half' => true],
                ['key' => 'status', 'label' => 'Statut', 'type' => 'select', 'default' => 'planned', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::SPRINT_STATUSES],
                ['key' => 'start_date', 'label' => 'Début', 'type' => 'date', 'list' => true, 'half' => true],
                ['key' => 'end_date', 'label' => 'Fin', 'type' => 'date', 'list' => true, 'half' => true],
                ['key' => 'progress', 'label' => 'Avancement', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'goal', 'label' => 'Objectif du sprint', 'type' => 'textarea'],
                ['key' => 'retro', 'label' => 'Rétrospective', 'type' => 'textarea'],
            ],
        ],
        'releases' => [
            'table' => 'dev_releases', 'label' => 'Version', 'plural' => 'Versions & mises en production', 'icon' => 'rocket_launch',
            'view' => $view, 'manage' => 'dev.manage', 'order' => 'id desc', 'date_field' => 'date', 'search' => ['title', 'notes'],
            'fields' => [
                ['key' => 'title', 'label' => 'Version', 'required' => true, 'list' => true, 'hint' => 'Ex. 2.4.0'],
                ['key' => 'app_id', 'label' => 'Application', 'type' => 'ref', 'resource' => 'apps', 'list' => true, 'half' => true, 'filter' => true],
                ['key' => 'environment', 'label' => 'Environnement', 'type' => 'select', 'default' => 'production', 'list' => true, 'half' => true, 'options' => H::ENVIRONMENTS],
                ['key' => 'status', 'label' => 'Statut', 'type' => 'select', 'default' => 'planned', 'list' => true, 'half' => true, 'filter' => true, 'options' => H::RELEASE_STATUSES],
                ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'list' => true, 'half' => true],
                ['key' => 'owner_id', 'label' => 'Responsable', 'type' => 'user', 'default' => '@me', 'half' => true],
                ['key' => 'items', 'label' => 'Tâches', 'type' => 'number', 'computed' => true, 'list' => true],
                ['key' => 'url', 'label' => 'Lien (tag, pipeline)', 'type' => 'url', 'half' => true],
                ['key' => 'notes', 'label' => 'Notes de version', 'type' => 'textarea', 'hint' => 'Bouton « Générer les notes » : à partir des tâches terminées de la version'],
            ],
        ],
        'apps' => [
            'table' => 'dev_apps', 'label' => 'Application', 'plural' => 'Applications', 'icon' => 'apps',
            'view' => $view, 'manage' => 'dev.manage', 'order' => 'title asc', 'search' => ['title', 'stack'],
            'fields' => [
                ['key' => 'title', 'label' => 'Application', 'required' => true, 'list' => true],
                ['key' => 'stack', 'label' => 'Technologies', 'list' => true, 'half' => true, 'hint' => 'Ex. Laravel, Flutter, MySQL'],
                ['key' => 'status', 'label' => 'Statut', 'type' => 'select', 'default' => 'active', 'list' => true, 'half' => true, 'options' => H::APP_STATUSES],
                ['key' => 'owner_id', 'label' => 'Responsable technique', 'type' => 'user', 'list' => true, 'half' => true],
                ['key' => 'open_items', 'label' => 'Tâches ouvertes', 'type' => 'number', 'computed' => true, 'list' => true, 'half' => true],
                ['key' => 'repo_url', 'label' => 'Dépôt Git', 'type' => 'url'],
                ['key' => 'prod_url', 'label' => 'Production', 'type' => 'url', 'half' => true],
                ['key' => 'staging_url', 'label' => 'Préproduction / recette', 'type' => 'url', 'half' => true],
                ['key' => 'notes', 'label' => 'Notes (accès, déploiement…)', 'type' => 'textarea'],
                ['key' => 'on_merge', 'label' => 'Quand une pull request est fusionnée, la tâche passe', 'type' => 'select', 'default' => 'testing', 'half' => true, 'options' => ['testing' => 'En recette', 'done' => 'Terminé']],
                ['key' => 'git_provider', 'label' => 'Hébergeur Git', 'type' => 'select', 'half' => true, 'options' => ['github' => 'GitHub', 'gitlab' => 'GitLab']],
                ['key' => 'webhook', 'label' => 'Webhook à créer dans GitHub / GitLab', 'type' => 'textarea', 'computed' => true],
            ],
        ],
    ],
];
