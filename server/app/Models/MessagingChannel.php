<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MessagingChannel extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'channel', 'provider', 'settings', 'enabled'];

    protected $hidden = ['settings'];

    protected function casts(): array
    {
        return ['settings' => 'encrypted:array', 'enabled' => 'boolean'];
    }

    public function setting(string $k, $default = null)
    {
        return $this->settings[$k] ?? $default;
    }
}
