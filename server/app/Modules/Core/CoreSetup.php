<?php

namespace App\Modules\Core;

use App\Models\Company;
use App\Modules\Core\Models\PaymentTerm;

/** Mise en place du socle : conditions de paiement par défaut. */
class CoreSetup
{
    public function __invoke(Company $company): void
    {
        PaymentTerm::ensureDefaults((int) $company->setting('payment_terms_days', 30));
    }
}
