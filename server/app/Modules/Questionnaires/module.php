<?php

return [
    'key' => 'questionnaires',
    'name' => 'Sondages & formulaires',
    'description' => 'Questionnaires de satisfaction, enquêtes NPS, formulaires d\'inscription ou d\'évaluation : questions de tous types (choix, notes, NPS, texte, nombre, date, contact), lien public et QR code à partager par WhatsApp, SMS ou e-mail, réponses en ligne sans compte, saisie dans l\'application, résultats en graphiques, score de satisfaction et NPS, alerte quand un client est mécontent, export Excel.',
    'category' => 'Marketing & communication',
    'icon' => 'quiz',
    'depends' => ['core'],
    'sort' => 58,
    'setup' => \App\Modules\Questionnaires\QuestionnairesSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'questionnaires.view' => 'Consulter les questionnaires et leurs résultats',
        'questionnaires.answer' => 'Saisir des réponses dans l\'application',
        'questionnaires.manage' => 'Créer et publier les questionnaires',
    ],
    'sync' => [],
];
