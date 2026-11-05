<?php

namespace App\Modules\Restaurant;

use App\Models\Company;
use App\Modules\Restaurant\Models\Order;
use App\Modules\Restaurant\Models\OrderLine;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $tz = $company->settings['timezone'] ?? config('erp.defaults.timezone', 'UTC');
        $from = now($tz)->startOfDay()->utc();

        return [
            ['key' => 'rs_open', 'label' => 'Tables occupées', 'value' => Order::where('status', 'open')->where('kind', 'dine_in')->distinct()->count('table_id'), 'format' => 'number', 'icon' => 'table_restaurant'],
            ['key' => 'rs_covers', 'label' => 'Couverts du jour', 'value' => (int) Order::where('status', '!=', 'cancelled')->where('kind', 'dine_in')->where('opened_at', '>=', $from)->sum('guests'), 'format' => 'number', 'icon' => 'restaurant'],
            ['key' => 'rs_waiting', 'label' => 'Plats en attente en cuisine', 'value' => OrderLine::whereIn('status', ['sent', 'preparing'])->count(), 'format' => 'number', 'icon' => 'soup_kitchen'],
        ];
    }
}
