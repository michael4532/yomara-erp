<?php

return [
    'key' => 'rh',
    'name' => 'Ressources humaines',
    'description' => 'Fiches salariés, fiches de poste et catégories, effectifs par service, contrats, congés et absences, documents RH (contrats, attestations, certificats, solde de tout compte), espace salarié — hors ligne.',
    'category' => 'Ressources humaines',
    'icon' => 'badge',
    'depends' => ['core'],
    'sort' => 60,
    'dashboard' => \App\Modules\Rh\Dashboard::class,
    'setup' => \App\Modules\Rh\RhSetup::class,
    'setup_version' => 2,
    'permissions' => [
        'rh.view' => 'Consulter les fiches salariés et les congés',
        'rh.manage' => 'Créer et modifier les salariés, valider les congés',
        'rh.self' => 'Espace salarié : sa fiche, ses bulletins, ses demandes de congés',
        'rh.request' => 'Faire des demandes depuis son espace (congé, augmentation, avance, démission…)',
        'rh.contracts' => 'Voir les contrats de travail des salariés (rédigés par le juriste ou émis par la RH)',
    ],
    'sync' => [
        'rh_employees' => \App\Modules\Rh\Models\Employee::class,
        'rh_leaves' => \App\Modules\Rh\Models\Leave::class,
        'rh_doc_templates' => \App\Modules\Rh\Models\DocTemplate::class,
        'rh_documents' => \App\Modules\Rh\Models\HrDocument::class,
        'rh_categories' => \App\Modules\Rh\Models\Category::class,
        'rh_positions' => \App\Modules\Rh\Models\Position::class,
        'rh_requests' => \App\Modules\Rh\Models\EmployeeRequest::class,
        'rh_departments' => \App\Modules\Rh\Models\Department::class,
        'rh_contracts' => \App\Modules\Rh\Models\Contract::class,
    ],
];
