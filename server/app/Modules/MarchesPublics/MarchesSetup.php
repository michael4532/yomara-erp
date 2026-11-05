<?php

namespace App\Modules\MarchesPublics;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Rôle « Chargé des marchés » et droits de la direction. */
class MarchesSetup
{
    public const ROLE = 'Chargé des marchés';

    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, ['Direction' => ['marches.view']], [
            self::ROLE => ['visio.use', 'mailbox.use', 'whatsapp.use', 'achats.request', 'fonds.request', 'marches.*', 'documents.*', 'signature.*', 'core.contacts.view', 'ventes.view', 'juridique.view'],
        ]);
    }
}
