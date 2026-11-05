<?php

namespace App\Modules\Fournisseurs;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $s = app(FournisseursService::class)->stats();
        $p = 'portail_fournisseurs.view|portail_fournisseurs.manage';

        return [
            ['key' => 'sp_invoices', 'label' => 'Factures fournisseurs déposées à traiter', 'value' => $s['invoices_received'], 'format' => 'number', 'icon' => 'receipt_long', 'permission' => $p],
            ['key' => 'sp_award', 'label' => 'Appels d\'offres à attribuer', 'value' => $s['to_award'], 'format' => 'number', 'icon' => 'gavel', 'permission' => $p],
        ];
    }
}
