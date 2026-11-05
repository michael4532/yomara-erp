<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CompanyModule extends Model
{
    protected $fillable = ['company_id', 'module_key', 'enabled', 'expires_at', 'price_override', 'source', 'billing', 'trial_used'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'expires_at' => 'datetime',
            'price_override' => 'float',
            'trial_used' => 'boolean',
        ];
    }

    public function isEffective(): bool
    {
        return $this->enabled && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
