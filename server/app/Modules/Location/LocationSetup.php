<?php

namespace App\Modules\Location;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Rôle « Gestionnaire location » et droits de la direction. */
class LocationSetup
{
    public const ROLE = 'Gestionnaire location';

    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, ['Direction' => ['location.view']], [
            self::ROLE => ['mailbox.use', 'whatsapp.use', 'location.*', 'core.contacts.view', 'core.contacts.manage', 'ventes.view', 'ventes.manage'],
        ]);
    }
}
