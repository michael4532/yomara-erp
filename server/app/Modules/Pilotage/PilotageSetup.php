<?php

namespace App\Modules\Pilotage;

use App\Models\Company;
use App\Support\ServiceRoles;

/** La direction et la comptabilité consultent ; la direction fixe les objectifs. */
class PilotageSetup
{
    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, ['Direction' => ['pilotage.view', 'pilotage.manage'], 'Comptable' => ['pilotage.view']]);
    }
}
