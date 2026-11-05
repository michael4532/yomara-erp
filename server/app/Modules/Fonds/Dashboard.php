<?php

namespace App\Modules\Fonds;

use App\Models\Company;
use App\Modules\Fonds\Models\FundRequest;

/** Indicateurs de l'accueil : appels de fonds à décider et à fournir. */
class Dashboard
{
    public function __invoke(Company $company): array
    {
        $open = FundRequest::whereIn('status', ['submitted', 'question', 'approved', 'partial'])->get();

        return [
            ['key' => 'fonds_decide', 'label' => 'Appels de fonds à décider', 'value' => $open->whereIn('status', ['submitted', 'question'])->count(), 'format' => 'number', 'icon' => 'request_page'],
            ['key' => 'fonds_provide', 'label' => 'Appels de fonds à fournir', 'value' => round($open->whereIn('status', ['approved', 'partial'])->sum(fn ($r) => $r->total_approved - $r->total_provided), 2), 'format' => 'money', 'icon' => 'payments'],
        ];
    }
}
