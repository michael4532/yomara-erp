<?php

namespace App\Modules\Qualite;

use App\Models\Company;

/** Indicateurs de l'accueil : non-conformités ouvertes et actions en retard. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $d = app(QualiteHooks::class)->dashboard($company);

        return [
            ['key' => 'qa_open', 'label' => 'Non-conformités ouvertes', 'value' => $d['open'], 'format' => 'number', 'icon' => 'report', 'permission' => 'qualite.view|qualite.manage'],
            ['key' => 'qa_late', 'label' => 'Actions qualité en retard', 'value' => $d['actions_late'], 'format' => 'number', 'icon' => 'task_alt', 'permission' => 'qualite.view|qualite.manage'],
        ];
    }
}
