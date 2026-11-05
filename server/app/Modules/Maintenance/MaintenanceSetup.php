<?php

namespace App\Modules\Maintenance;

use App\Models\Company;
use App\Support\ServiceRoles;

/** Rôles « Responsable maintenance » et « Technicien maintenance » ; tout le monde peut signaler une panne. */
class MaintenanceSetup
{
    public function __invoke(Company $company): void
    {
        $all = ['maintenance.request'];
        ServiceRoles::apply($company, ['Direction' => ['maintenance.view', 'maintenance.request'], 'Commercial' => $all, 'Caissier' => $all, 'Comptable' => $all, 'Magasinier' => $all, 'Acheteur' => $all, 'RH / Paie' => $all, 'Équipe projet' => $all, 'Technicien' => $all], [
            'Responsable maintenance' => ['mailbox.use', 'whatsapp.use', 'achats.request', 'maintenance.*', 'stock.view'],
            'Technicien maintenance' => ['maintenance.request', 'maintenance.view', 'maintenance.work'],
        ]);
    }
}
