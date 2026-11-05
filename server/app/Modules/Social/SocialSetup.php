<?php

namespace App\Modules\Social;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Droits par défaut : la direction valide et connecte les comptes, le service marketing rédige et planifie. */
class SocialSetup
{
    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, [
            'Direction' => ['social.view', 'social.publish', 'social.accounts', 'social.inbox'],
            'Marketing & communication' => ['social.view', 'social.manage', 'social.inbox'],
            'Commercial' => ['social.inbox'],
        ], ['Marketing & communication' => ['social.view', 'social.manage', 'social.inbox']]);
    }
}
