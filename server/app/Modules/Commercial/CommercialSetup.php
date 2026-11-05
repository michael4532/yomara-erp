<?php

namespace App\Modules\Commercial;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Les commerciaux gèrent leur terrain ; le responsable commercial fixe les objectifs ; la direction suit. */
class CommercialSetup
{
    public const ROLE = 'Responsable commercial';

    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, ['Commercial' => ['commercial.field'], 'Direction' => ['commercial.view']], [
            self::ROLE => ['visio.use', 'visio.webinar', 'mailbox.use', 'whatsapp.use', 'achats.request', 'fonds.request', 'commercial.*', 'crm.*', 'ventes.view', 'ventes.manage', 'ventes.validate',
                'ventes.payments.manage', 'core.contacts.view', 'core.contacts.manage', 'core.products.view', 'stock.view', 'flotte.request', 'contenus.view', 'contenus.watch', 'relances.view', 'portail.manage'],
        ]);
    }
}
