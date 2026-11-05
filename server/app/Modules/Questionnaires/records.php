<?php

use App\Modules\Questionnaires\QuestionnaireService as S;

$view = 'questionnaires.view|questionnaires.answer|questionnaires.manage';

return [
    'hooks' => S::class,
    'resources' => [
        'forms' => [
            'table' => 'qs_forms', 'label' => 'Questionnaire', 'plural' => 'Questionnaires', 'icon' => 'quiz',
            'view' => $view, 'manage' => 'questionnaires.manage', 'order' => 'id desc', 'search' => ['title', 'description'],
            'hint' => 'Créez le questionnaire, ajoutez les questions, ouvrez-le puis partagez le lien ou le QR code.',
            'fields' => [
                ['key' => 'title', 'label' => 'Titre', 'required' => true, 'list' => true],
                ['key' => 'kind', 'label' => 'Type', 'type' => 'select', 'default' => 'satisfaction', 'list' => true, 'half' => true, 'filter' => true, 'options' => S::KINDS, 'hint' => 'Les questions de départ dépendent du type'],
                ['key' => 'status', 'label' => 'État', 'type' => 'select', 'default' => 'draft', 'list' => true, 'half' => true, 'filter' => true, 'options' => S::STATUSES],
                ['key' => 'owner_id', 'label' => 'Responsable', 'type' => 'user', 'default' => '@me', 'half' => true],
                ['key' => 'closes_on', 'label' => 'Fermeture automatique le', 'type' => 'date', 'half' => true],
                ['key' => 'responses_count', 'label' => 'Réponses', 'type' => 'number', 'readonly' => true, 'list' => true, 'half' => true],
                ['key' => 'summary', 'label' => 'Résultat', 'type' => 'text', 'computed' => true, 'list' => true],
                ['key' => 'max_responses', 'label' => 'Nombre maximum de réponses', 'type' => 'number', 'half' => true],
                ['key' => 'ask_contact', 'label' => 'Demander nom et téléphone (facultatifs)', 'type' => 'bool', 'default' => true, 'half' => true],
                ['key' => 'alert_unhappy', 'label' => 'Me prévenir quand un répondant est mécontent', 'type' => 'bool', 'default' => true, 'half' => true],
                ['key' => 'description', 'label' => 'Introduction affichée en haut', 'type' => 'textarea'],
                ['key' => 'thank_you', 'label' => 'Message de remerciement', 'type' => 'textarea', 'max' => 500],
            ],
        ],
        'responses' => [
            'table' => 'qs_responses', 'label' => 'Réponse', 'plural' => 'Réponses', 'icon' => 'how_to_vote',
            'view' => $view, 'manage' => 'questionnaires.manage', 'order' => 'submitted_at desc', 'date_field' => 'submitted_at', 'search' => ['name', 'email', 'phone', 'notes'],
            'hint' => 'Réponses reçues par le lien ou saisies dans l\'application.',
            'fields' => [
                ['key' => 'form_id', 'label' => 'Questionnaire', 'type' => 'ref', 'resource' => 'forms', 'required' => true, 'list' => true, 'filter' => true],
                ['key' => 'submitted_at', 'label' => 'Reçue le', 'type' => 'datetime', 'readonly' => true, 'list' => true, 'half' => true],
                ['key' => 'mood', 'label' => 'Satisfaction', 'type' => 'select', 'readonly' => true, 'list' => true, 'half' => true, 'filter' => true, 'options' => S::MOODS],
                ['key' => 'name', 'label' => 'Nom', 'list' => true, 'half' => true],
                ['key' => 'phone', 'label' => 'Téléphone', 'list' => true, 'half' => true],
                ['key' => 'email', 'label' => 'E-mail', 'half' => true],
                ['key' => 'partner_id', 'label' => 'Client', 'type' => 'partner', 'half' => true],
                ['key' => 'digest', 'label' => 'Réponses', 'type' => 'text', 'computed' => true],
                ['key' => 'source', 'label' => 'Reçue par', 'type' => 'select', 'readonly' => true, 'half' => true, 'options' => S::SOURCES],
                ['key' => 'notes', 'label' => 'Suite donnée (rappel du client, geste…)', 'type' => 'textarea'],
            ],
        ],
    ],
];
