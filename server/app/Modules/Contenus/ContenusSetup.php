<?php

namespace App\Modules\Contenus;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Droits par défaut : la direction valide, le service marketing rédige, les commerciaux remontent la veille terrain. */
class ContenusSetup
{
    public const ROLE = 'Marketing & communication';

    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, [
            'Direction' => ['contenus.validate'],
            self::ROLE => ['contenus.view', 'contenus.manage'],
            'Commercial' => ['contenus.view', 'contenus.watch'],
        ], [self::ROLE => ['visio.use', 'visio.webinar', 'mailbox.use', 'whatsapp.use', 'achats.request', 'fonds.request', 'contenus.view', 'contenus.manage', 'marketing.*', 'avis.*', 'sites.*', 'sms.*', 'core.contacts.view', 'core.products.view']]);
    }
}
