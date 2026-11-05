<?php

return [
    'key' => 'social',
    'name' => 'Réseaux sociaux',
    'description' => 'Publiez sur tous vos réseaux depuis un seul écran : Facebook, Instagram, Threads, LinkedIn, X, TikTok, Pinterest, YouTube, Google Business, Telegram, Mastodon, Bluesky et Sensya. Calendrier de planification, publication simultanée sur plusieurs comptes, textes adaptés automatiquement à la limite de caractères de chaque réseau, idées et hashtags suggérés par l\'IA, circuit de validation, meilleures heures et statistiques, boîte de réception des commentaires et messages Facebook et Instagram avec réponses automatiques, charte de marque et visuels créés depuis votre catalogue.',
    'category' => 'Marketing & communication',
    'icon' => 'share',
    'depends' => ['core'],
    'sort' => 62,
    'setup' => \App\Modules\Social\SocialSetup::class,
    'setup_version' => 2,
    'permissions' => [
        'social.view' => 'Consulter le calendrier, les publications et les statistiques',
        'social.manage' => 'Rédiger et planifier des publications (soumises à validation sans le droit « Publier »)',
        'social.publish' => 'Valider et publier sur les réseaux sans validation préalable',
        'social.accounts' => 'Connecter et déconnecter les comptes de réseaux sociaux',
        'social.inbox' => 'Lire et répondre aux commentaires et messages (boîte de réception)',
    ],
    'sync' => [],
];
