<?php

namespace App\Modules\Qualite;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Rôles « Responsable qualité » et « Contrôleur qualité » ; tout le monde peut signaler une non-conformité. */
class QualiteSetup
{
    public function __invoke(Company $company): void
    {
        $r = ['qualite.report'];
        ServiceRoles::apply($company, ['Direction' => ['qualite.view', 'qualite.report'], 'Commercial' => $r, 'Caissier' => $r, 'Magasinier' => ['qualite.report', 'qualite.inspect'],
            'Acheteur' => $r, 'Comptable' => $r, 'RH / Paie' => $r, 'Équipe projet' => $r, 'Technicien' => $r, 'Livreur' => $r], [
            'Responsable qualité' => ['mailbox.use', 'whatsapp.use', 'achats.request', 'qualite.*', 'core.contacts.view', 'stock.view'],
            'Contrôleur qualité' => ['qualite.report', 'qualite.inspect', 'qualite.view'],
        ]);
    }
}
