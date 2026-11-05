<?php

namespace App\Modules\Core;

use App\Models\Company;
use App\Models\User;
use App\Modules\Core\Models\Partner;
use App\Modules\Core\Models\Product;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        return [
            ['key' => 'customers', 'label' => 'Clients', 'value' => Partner::whereIn('type', ['customer', 'both'])->count(), 'format' => 'number', 'icon' => 'people', 'permission' => 'core.contacts.view'],
            ['key' => 'products', 'label' => 'Produits actifs', 'value' => Product::where('active', true)->count(), 'format' => 'number', 'icon' => 'inventory_2', 'permission' => 'core.products.view'],
            ['key' => 'online_users', 'label' => 'Utilisateurs connectés', 'value' => User::where('company_id', $company->id)
                ->where('last_seen_at', '>', now()->subSeconds(config('erp.presence_window')))->count(), 'format' => 'number', 'icon' => 'wifi', 'permission' => 'core.users.manage'],
        ];
    }
}
