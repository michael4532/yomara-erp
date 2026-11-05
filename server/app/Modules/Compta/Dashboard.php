<?php

namespace App\Modules\Compta;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        Chart::ensure($company);
        $ledger = app(Ledger::class);
        $result = $ledger->incomeStatement($company, FiscalCalendar::startOf($company, now()->toDateString()), now()->toDateString())['resultat'];
        $vat = $ledger->vat($company, now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString());

        return [
            ['key' => 'treasury', 'label' => 'Trésorerie (caisse, banque, Mobile Money)', 'value' => $ledger->treasury($company), 'format' => 'money', 'icon' => 'account_balance_wallet'],
            ['key' => 'result_ytd', 'label' => 'Résultat de l\'exercice', 'value' => $result, 'format' => 'money', 'icon' => 'insights', 'alert' => $result < 0],
            ['key' => 'vat_due', 'label' => 'TVA à payer ce mois', 'value' => $vat['due'], 'format' => 'money', 'icon' => 'account_balance'],
        ];
    }
}
