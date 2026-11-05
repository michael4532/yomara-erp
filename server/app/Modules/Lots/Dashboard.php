<?php

namespace App\Modules\Lots;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $rows = collect(app(LotService::class)->levels());

        return [
            ['key' => 'lots_expired', 'label' => 'Lots périmés en stock', 'value' => $rows->where('status', 'expired')->pluck('lot_id')->unique()->count(), 'format' => 'number', 'icon' => 'event_busy',
                'alert' => $rows->where('status', 'expired')->isNotEmpty(), 'permission' => 'stock.view|stock.manage'],
            ['key' => 'lots_expiring', 'label' => 'Lots à écouler (péremption proche)', 'value' => $rows->where('status', 'expiring')->pluck('lot_id')->unique()->count(), 'format' => 'number', 'icon' => 'schedule',
                'permission' => 'stock.view|stock.manage'],
        ];
    }
}
