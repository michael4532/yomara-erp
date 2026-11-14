<?php

return [
    'key' => 'pdf',
    'name' => 'Outils PDF',
    'description' => 'Tous les outils PDF du quotidien, comme Acrobat : fusionner, découper, réorganiser et pivoter les pages, ajouter un filigrane, du texte, une image ou une signature, numéroter les pages, protéger par mot de passe (chiffrement AES-256) ou retirer la protection, convertir des images en PDF. Les fichiers envoyés sont aussitôt effacés ; ceux que vous produisez restent 30 jours dans votre historique personnel.',
    'category' => 'Collaboration',
    'icon' => 'picture_as_pdf',
    'depends' => ['core'],
    'sort' => 64,
    'setup' => \App\Modules\Pdf\PdfSetup::class,
    'setup_version' => 1,
    'permissions' => [
        'pdf.use' => 'Utiliser les outils PDF',
    ],
    'sync' => [],
];
