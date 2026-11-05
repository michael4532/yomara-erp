<?php

return [
    'key' => 'notes_frais',
    'name' => 'Notes de frais',
    'description' => 'Dépenses saisies par les salariés avec photo du justificatif (hors ligne), validation par le responsable, remboursement par la trésorerie ou sur la paie, écritures comptables automatiques.',
    'category' => 'Finance',
    'icon' => 'request_quote',
    'depends' => ['rh'],
    'sort' => 64,
    'provider' => \App\Modules\NotesFrais\NotesFraisModuleProvider::class,
    'setup' => \App\Modules\NotesFrais\NotesFraisSetup::class,
    'setup_version' => 1,
    'dashboard' => \App\Modules\NotesFrais\Dashboard::class,
    'permissions' => [
        'notes_frais.self' => 'Saisir ses propres notes de frais',
        'notes_frais.approve' => 'Valider ou refuser les notes de frais',
        'notes_frais.manage' => 'Rembourser, catégories et réglages',
    ],
    'sync' => [
        'nf_categories' => \App\Modules\NotesFrais\Models\ExpenseCategory::class,
        'nf_expenses' => \App\Modules\NotesFrais\Models\Expense::class,
    ],
];
