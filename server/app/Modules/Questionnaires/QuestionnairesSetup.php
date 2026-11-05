<?php

namespace App\Modules\Questionnaires;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Marketing et direction créent les questionnaires ; les équipes au contact des clients saisissent les réponses. */
class QuestionnairesSetup
{
    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, ['Direction' => ['questionnaires.view', 'questionnaires.manage'], 'Marketing & communication' => ['questionnaires.*'],
            'Commercial' => ['questionnaires.view', 'questionnaires.answer'], 'Caissier' => ['questionnaires.answer'], 'RH / Paie' => ['questionnaires.view', 'questionnaires.manage']]);
    }
}
