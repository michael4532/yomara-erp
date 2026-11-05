<?php

return [
    'key' => 'documents',
    'name' => 'Documents (GED)',
    'description' => 'Dossiers et fichiers de l\'entreprise : droits par rôle, versions, recherche dans le contenu des PDF et documents Word, liens de partage, échéances, corbeille, classement automatique des documents signés.',
    'category' => 'Collaboration',
    'icon' => 'folder',
    'depends' => ['core'],
    'sort' => 71,
    'setup' => \App\Modules\Documents\DocumentsSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\Documents\Dashboard::class,
    'permissions' => [
        'documents.view' => 'Consulter les documents des dossiers autorisés',
        'documents.manage' => 'Déposer, modifier, partager et supprimer des documents',
        'documents.admin' => 'Gérer les droits des dossiers, voir tous les dossiers, vider la corbeille',
    ],
    'sync' => [
        'dc_folders' => \App\Modules\Documents\Models\Folder::class,
        'dc_files' => \App\Modules\Documents\Models\DocFile::class,
    ],
];
