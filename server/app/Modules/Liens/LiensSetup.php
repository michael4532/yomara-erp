<?php

namespace App\Modules\Liens;

use App\Models\Company;
use App\Support\ServiceRoles;

class LiensSetup
{
    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, ['Direction' => ['liens.view', 'liens.manage'], 'Marketing & communication' => ['liens.view', 'liens.manage'], 'Commercial' => ['liens.view']]);
    }
}
