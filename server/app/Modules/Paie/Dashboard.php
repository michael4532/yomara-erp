<?php

namespace App\Modules\Paie;

use App\Models\Company;
use App\Modules\Paie\Models\PayrollRun;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $last = PayrollRun::whereIn('status', ['validated', 'paid'])->orderByDesc('period')->first();

        return [
            ['key' => 'paie_mass', 'label' => 'Masse salariale'.($last ? ' '.$last->period : ''), 'value' => (float) ($last->totals['gross'] ?? 0), 'format' => 'money', 'icon' => 'payments'],
            ['key' => 'paie_cost', 'label' => 'Coût employeur'.($last ? ' '.$last->period : ''), 'value' => (float) ($last->totals['cost'] ?? 0), 'format' => 'money', 'icon' => 'account_balance_wallet'],
        ];
    }
}
