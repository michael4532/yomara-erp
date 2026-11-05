<?php

namespace App\Modules\Interventions;

use App\Models\Company;
use App\Modules\Interventions\Models\Intervention;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        return [
            ['key' => 'it_today', 'label' => 'Interventions du jour', 'value' => Intervention::whereDate('scheduled_at', today())->where('status', '!=', 'cancelled')->count(), 'format' => 'number', 'icon' => 'engineering'],
            ['key' => 'it_requests', 'label' => 'Demandes d\'intervention à planifier', 'value' => Intervention::where('status', 'requested')->count(), 'format' => 'number', 'icon' => 'support_agent', 'permission' => 'interventions.manage'],
            ['key' => 'it_to_invoice', 'label' => 'Interventions à facturer', 'value' => Intervention::where('status', 'done')->where('billable', true)->whereNull('invoice_id')->count(), 'format' => 'number', 'icon' => 'request_quote', 'permission' => 'interventions.manage'],
        ];
    }
}
