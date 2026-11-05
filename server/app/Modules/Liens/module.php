<?php

return [
    'key' => 'liens',
    'name' => 'Liens, smartlinks & QR codes',
    'description' => 'Raccourcissez et masquez vos liens, créez des smartlinks (une page avec toutes vos plateformes, ou une redirection selon l\'appareil et le pays) et des QR codes à partir de n\'importe quel contenu : site, WhatsApp, Wi-Fi, carte de visite… Statistiques des clics, de la portée, des pays, des appareils et des sources.',
    'category' => 'Marketing & communication',
    'icon' => 'link',
    'depends' => ['core'],
    'sort' => 63,
    'setup' => \App\Modules\Liens\LiensSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'liens.view' => 'Consulter les liens, QR codes et statistiques',
        'liens.manage' => 'Créer et modifier les liens, smartlinks et QR codes',
    ],
    'sync' => [],
];
