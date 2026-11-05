<?php

namespace App\Modules\Fidelite;

use App\Models\Company;
use App\Modules\Fidelite\Models\GiftCard;
use App\Modules\Fidelite\Models\LoyaltyCard;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $value = app(LoyaltyService::class)->settings($company)['point_value'];

        return [
            ['key' => 'fid_members', 'label' => 'Clients fidèles (cartes actives)', 'value' => LoyaltyCard::where('status', 'active')->count(), 'format' => 'number', 'icon' => 'loyalty'],
            ['key' => 'fid_liability', 'label' => 'Points et cartes cadeaux en circulation', 'value' => round(LoyaltyCard::where('status', 'active')->sum('points') * $value + (float) GiftCard::where('status', 'active')->sum('balance'), 2), 'format' => 'money', 'icon' => 'savings', 'permission' => 'fidelite.view'],
        ];
    }
}
