<?php

namespace App\Modules\Analytique;

use App\Models\Company;
use App\Modules\Analytique\Models\Budget;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $today = now()->toDateString();
        $over = 0;
        foreach (Budget::with('lines')->where('status', 'approved')->where('start_date', '<=', $today)->where('end_date', '>=', $today)->get() as $b) {
            foreach ($b->lines->where('kind', 'expense') as $l) {
                if ($l->alerted >= 100) {
                    $over++;
                }
            }
        }

        return [
            ['key' => 'bg_over', 'label' => 'Lignes de budget dépassées', 'value' => $over, 'format' => 'number', 'icon' => 'warning', 'permission' => 'analytique.view|analytique.manage'],
        ];
    }
}
