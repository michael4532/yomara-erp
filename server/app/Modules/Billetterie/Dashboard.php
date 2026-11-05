<?php

namespace App\Modules\Billetterie;

use App\Models\Company;
use App\Modules\Billetterie\Models\Ticket;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        return [
            ['key' => 'tk_sold', 'label' => 'Billets émis (événements à venir)', 'value' => Ticket::where('status', 'valid')->whereIn('kind', ['visitor', 'vip'])->where('valid_to', '>=', now()->toDateString())->count(),
                'format' => 'number', 'icon' => 'confirmation_number', 'permission' => 'billetterie.sell|billetterie.manage'],
        ];
    }
}
