<?php

namespace App\Modules\Tresorerie;

use App\Models\Company;
use App\Modules\Tresorerie\Models\StatementLine;
use App\Modules\Tresorerie\Models\TreasuryAccount;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $svc = app(TreasuryService::class);

        return [
            ['key' => 'tr_cash', 'label' => 'Trésorerie disponible', 'value' => round(TreasuryAccount::where('active', true)->get()->sum(fn ($a) => $svc->balance($a))), 'format' => 'money', 'icon' => 'savings', 'permission' => 'tresorerie.view'],
            ['key' => 'tr_open', 'label' => 'Lignes de relevé à rapprocher', 'value' => StatementLine::where('status', 'open')->count(), 'format' => 'number', 'icon' => 'compare_arrows', 'permission' => 'tresorerie.manage'],
        ];
    }
}
