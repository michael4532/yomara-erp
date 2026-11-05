<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    protected $fillable = [
        'code', 'name', 'description', 'price_monthly', 'price_yearly', 'currency',
        'max_users', 'modules', 'is_active', 'sort', 'is_free', 'yearly_discount', 'intro_discount', 'intro_months', 'badge',
    ];

    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'is_active' => 'boolean',
            'price_monthly' => 'float',
            'price_yearly' => 'float',
            'max_users' => 'integer',
            'is_free' => 'boolean',
            'yearly_discount' => 'float',
            'intro_discount' => 'float',
            'intro_months' => 'integer',
        ];
    }

    /** Prix annuel : 12 mois moins la remise annuelle (arrondi à la centaine). */
    public function yearlyPrice(): float
    {
        if ($this->yearly_discount > 0) {
            return round($this->price_monthly * 12 * (100 - $this->yearly_discount) / 100 / 100) * 100;
        }

        return (float) ($this->price_yearly ?: $this->price_monthly * 12);
    }

    public function introPrice(): float
    {
        return round($this->price_monthly * (100 - $this->intro_discount) / 100 / 100) * 100;
    }

    /** Vue publique : prix, remises et économies. */
    public function offer(?int $paidMonths = null): array
    {
        $introLeft = $this->intro_discount > 0 && $this->intro_months > 0 ? max(0, $this->intro_months - (int) $paidMonths) : 0;

        return [
            'code' => $this->code, 'name' => $this->name, 'description' => $this->description, 'is_free' => (bool) $this->is_free || $this->price_monthly <= 0,
            'max_users' => $this->max_users, 'badge' => $this->badge, 'currency' => $this->currency,
            'monthly' => (float) $this->price_monthly,
            'monthly_now' => $introLeft > 0 ? $this->introPrice() : (float) $this->price_monthly,
            'intro_discount' => $this->intro_discount, 'intro_months' => $this->intro_months, 'intro_left' => $introLeft,
            'yearly' => $this->yearlyPrice(), 'yearly_discount' => $this->yearly_discount,
            'yearly_saving' => max(0, $this->price_monthly * 12 - $this->yearlyPrice()),
            'modules' => $this->modules ?? [],
        ];
    }
}
