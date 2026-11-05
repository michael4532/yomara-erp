<?php

namespace App\Modules\Hebergement;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $s = app(HostingService::class)->stats($company);
        $p = 'hebergement.view|hebergement.manage';

        return [
            ['key' => 'hb_expiring', 'label' => 'Services clients à échéance (30 j)', 'value' => $s['expiring_30'], 'format' => 'number', 'icon' => 'dns', 'permission' => $p],
            ['key' => 'hb_provider', 'label' => 'Renouvellements à faire chez le fournisseur', 'value' => $s['provider_todo'], 'format' => 'number', 'icon' => 'published_with_changes', 'permission' => $p],
        ];
    }
}
