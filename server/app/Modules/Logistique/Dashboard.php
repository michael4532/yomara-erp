<?php

namespace App\Modules\Logistique;

use App\Models\Company;
use App\Modules\Logistique\Models\Delivery;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $today = now()->toDateString();

        return [
            ['key' => 'lg_today', 'label' => 'Livraisons du jour', 'value' => Delivery::where('scheduled_date', $today)->whereNotIn('status', ['cancelled'])->count(), 'format' => 'number', 'icon' => 'local_shipping'],
            ['key' => 'lg_late', 'label' => 'Livraisons en retard', 'value' => Delivery::whereIn('status', ['planned', 'in_transit', 'failed'])->where('scheduled_date', '<', $today)->count(), 'format' => 'number', 'icon' => 'alarm', 'permission' => 'logistique.view'],
            ['key' => 'lg_cod', 'label' => 'Encaissements livreurs à remettre', 'value' => round((float) Delivery::where('status', 'delivered')->whereNull('cod_remitted_at')->sum('cod_collected'), 2), 'format' => 'money', 'icon' => 'payments', 'permission' => 'logistique.view'],
        ];
    }
}
