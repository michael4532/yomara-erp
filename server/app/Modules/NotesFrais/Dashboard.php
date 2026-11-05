<?php

namespace App\Modules\NotesFrais;

use App\Models\Company;
use App\Modules\NotesFrais\Models\Expense;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        return [
            ['key' => 'nf_submitted', 'label' => 'Notes de frais à valider', 'value' => Expense::where('status', 'submitted')->count(), 'format' => 'number', 'icon' => 'request_quote', 'permission' => 'notes_frais.approve|notes_frais.manage'],
            ['key' => 'nf_to_pay', 'label' => 'Notes de frais à rembourser', 'value' => (float) Expense::where('status', 'approved')->sum('amount'), 'format' => 'money', 'icon' => 'payments', 'permission' => 'notes_frais.manage'],
        ];
    }
}
