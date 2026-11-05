<?php

namespace App\Modules\Compta\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToCompany;

    protected $table = 'compta_accounts';

    protected $fillable = ['company_id', 'code', 'name', 'is_system', 'active'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'active' => 'boolean'];
    }

    public function getClassAttribute(): int
    {
        return (int) substr($this->code, 0, 1);
    }

    protected $appends = ['class'];
}
