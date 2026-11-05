<?php

return [
    'key' => 'signature',
    'name' => 'Signature électronique',
    'description' => 'Faites signer contrats, devis et tout document PDF en ligne : lien personnel par e-mail, WhatsApp ou SMS, code de vérification, signature au doigt, document scellé avec certificat de preuve.',
    'category' => 'Collaboration',
    'icon' => 'draw',
    'depends' => ['core'],
    'sort' => 72,
    'setup' => \App\Modules\Signature\SignatureSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Signature\Dashboard::class,
    'permissions' => [
        'signature.view' => 'Consulter les demandes de signature',
        'signature.manage' => 'Créer et envoyer des demandes de signature',
    ],
];
