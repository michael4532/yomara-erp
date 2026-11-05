<?php

return [
    'key' => 'etiquettes',
    'name' => 'Étiquettes & codes-barres',
    'description' => 'Étiquettes produits et de rayon sur planche A4 ou imprimante d\'étiquettes, codes EAN-13 internes, étiquettes de pesée lues par la caisse, réimpression après changement de prix.',
    'category' => 'Achats & stocks',
    'icon' => 'qr_code_2',
    'depends' => ['core'],
    'sort' => 43,
    'permissions' => [
        'etiquettes.print' => 'Imprimer des étiquettes',
        'etiquettes.manage' => 'Gérer les modèles et attribuer les codes-barres',
    ],
    'sync' => [
        'lb_templates' => \App\Modules\Etiquettes\Models\LabelTemplate::class,
        'lb_prints' => \App\Modules\Etiquettes\Models\LabelPrint::class,
    ],
];
