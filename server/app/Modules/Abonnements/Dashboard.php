<?php

namespace App\Modules\Abonnements;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $s = app(SubscriptionService::class)->stats($company);

        return [
            ['key' => 'ab_mrr', 'label' => 'Revenu récurrent mensuel (HT)', 'value' => $s['mrr'], 'format' => 'money', 'icon' => 'autorenew', 'permission' => 'abonnements.view|abonnements.manage'],
        ];
    }
}
