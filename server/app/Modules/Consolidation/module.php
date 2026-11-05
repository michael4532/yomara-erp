<?php

// Le code de la consolidation vit dans le module Comptabilité (App\Modules\Compta\Consolidation) ; ce manifeste en fait un module vendu à part.
return [
    'key' => 'consolidation',
    'name' => 'Consolidation de groupe',
    'description' => 'Comptes consolidés d\'un groupe de sociétés gérées dans Yomara : périmètre, éliminations, minoritaires, devises, états consolidés.',
    'category' => 'Finance',
    'icon' => 'account_tree',
    'depends' => ['compta'],
    'sort' => 51,
    'permissions' => [],
    'sync' => [],
];
