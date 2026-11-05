<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Module extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'category', 'icon', 'depends', 'is_core',
        'is_available', 'is_active', 'price_monthly', 'price_yearly', 'currency', 'sort',
        'billing_mode', 'price_one_time', 'trial_days', 'in_store', 'tagline', 'long_description', 'features', 'screenshots', 'demo_url',
    ];

    public const BILLING_MODES = ['free' => 'Gratuit / inclus', 'one_time' => 'Prix fixe (achat définitif)', 'subscription' => 'Abonnement', 'both' => 'Prix fixe ou abonnement'];

    /** Options d'achat proposées au client : ['monthly' => 7500, 'yearly' => 75000, 'one_time' => 150000]. */
    public function offers(?float $override = null): array
    {
        $out = [];
        if (in_array($this->billing_mode, ['subscription', 'both'], true)) {
            if ($this->price_monthly > 0 || $override !== null) {
                $out['monthly'] = $override ?? $this->price_monthly;
            }
            if ($this->price_yearly > 0) {
                $out['yearly'] = $this->price_yearly;
            }
        }
        if (in_array($this->billing_mode, ['one_time', 'both'], true) && $this->price_one_time > 0) {
            $out['one_time'] = $this->price_one_time;
        }

        return $out;
    }

    protected function casts(): array
    {
        return [
            'depends' => 'array',
            'is_core' => 'boolean',
            'is_available' => 'boolean',
            'is_active' => 'boolean',
            'price_monthly' => 'float',
            'price_yearly' => 'float',
            'price_one_time' => 'float',
            'trial_days' => 'integer',
            'in_store' => 'boolean',
            'features' => 'array',
            'screenshots' => 'array',
        ];
    }
}
