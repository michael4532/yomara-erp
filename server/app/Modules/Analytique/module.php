<?php

return [
    'key' => 'analytique',
    'name' => 'Analytique & budgets',
    'description' => 'Axes et sections analytiques (activités, sites, projets…), ventilation des ventes, achats, notes de frais et paie, règles automatiques, marge par section, budgets mensuels comparés au réalisé et aux engagements, alertes de dépassement.',
    'category' => 'Finance',
    'icon' => 'pie_chart',
    'depends' => ['core'],
    'sort' => 52,
    'setup' => \App\Modules\Analytique\AnalytiqueSetup::class,
    'setup_version' => 1,
    'provider' => \App\Modules\Analytique\AnalytiqueModuleProvider::class,
    'dashboard' => \App\Modules\Analytique\Dashboard::class,
    'permissions' => [
        'analytique.view' => 'Consulter l\'analytique et les budgets',
        'analytique.manage' => 'Gérer les axes, sections, règles et budgets',
    ],
    'sync' => [
        'an_axes' => \App\Modules\Analytique\Models\Axis::class,
        'an_sections' => \App\Modules\Analytique\Models\Section::class,
        'an_rules' => \App\Modules\Analytique\Models\Rule::class,
    ],
];
