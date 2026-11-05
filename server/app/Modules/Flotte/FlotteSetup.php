<?php

namespace App\Modules\Flotte;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Les commerciaux, techniciens et chefs de projet demandent un véhicule ; la logistique gère le parc. */
class FlotteSetup
{
    public const ROLE = 'Responsable parc auto';

    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, [
            'Commercial' => ['flotte.request'], 'Technicien' => ['flotte.request'], 'Responsable SAV' => ['flotte.request'], 'Chef de projet' => ['flotte.request'],
            'Équipe projet' => ['flotte.request'], 'Livreur' => ['flotte.request'], 'Logisticien' => ['flotte.*'], 'Direction' => ['flotte.request'],
        ], [self::ROLE => ['mailbox.use', 'whatsapp.use', 'achats.request', 'fonds.request', 'flotte.*', 'logistique.view', 'core.contacts.view']]);
    }
}
