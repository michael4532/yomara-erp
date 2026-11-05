<?php

return [
    'key' => 'visio',
    'name' => 'Yomeet : réunions & webinaires',
    'description' => 'Yomeet, la visioconférence intégrée à l\'ERP : lien et code d\'accès à partager, minutes incluses chaque mois et forfaits de minutes en plus ; réunions planifiées ou instantanées, invités externes par lien, salle d\'attente, partage d\'écran, conversation, réactions, main levée, sondages, sous-titres et transcription en direct, enregistrement, compte rendu automatique (résumé, décisions, actions transformées en tâches) envoyé aux participants. Webinaires avec page d\'inscription, rappels, intervenants et spectateurs, questions-réponses votées, rediffusion, statistiques et prospects CRM. Double infrastructure ZEGOCLOUD et Agora avec bascule automatique et invisible si l\'une est saturée, à court de crédit ou instable, plus un mode pair-à-pair de secours.',
    'category' => 'Collaboration',
    'icon' => 'video_call',
    'depends' => ['core'],
    'sort' => 40,
    'permissions' => [
        'visio.use' => 'Organiser et rejoindre des réunions vidéo',
        'visio.webinar' => 'Créer et animer des webinaires',
        'visio.manage' => 'Paramétrer Yomeet (options par défaut, minutes) et voir toutes les réunions',
    ],
    'sync' => [],
];
