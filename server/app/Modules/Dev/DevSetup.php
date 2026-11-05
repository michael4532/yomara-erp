<?php

namespace App\Modules\Dev;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Rôles du service développement : développeur et responsable technique. */
class DevSetup
{
    public function __invoke(Company $company): void
    {
        ServiceRoles::apply($company, ['Direction' => ['dev.view'], 'Chef de projet' => ['dev.view', 'dev.contribute']], [
            'Développeur' => ['visio.use', 'mailbox.use', 'whatsapp.use', 'achats.request', 'fonds.request', 'dev.view', 'dev.contribute', 'tickets.*', 'projets.view', 'projets.contribute', 'helpdesk.view', 'core.contacts.view'],
            'Responsable technique' => ['visio.use', 'visio.webinar', 'mailbox.use', 'whatsapp.use', 'achats.request', 'fonds.request', 'dev.*', 'tickets.*', 'projets.*', 'helpdesk.view', 'core.contacts.view'],
        ]);
    }
}
