<?php

namespace App\Modules\Tresorerie;

use App\Models\Company;
use App\Modules\Tresorerie\Models\TreasuryAccount;

/** Comptes créés avant la v0.22 : leurs soldes d'ouverture sont passés en comptabilité. */
class TresorerieSetup
{
    public function __invoke(Company $company): void
    {
        app()->instance('tenant', $company);
        foreach (TreasuryAccount::all() as $acc) {
            app(TreasuryService::class)->postOpening($acc);
        }
    }
}
