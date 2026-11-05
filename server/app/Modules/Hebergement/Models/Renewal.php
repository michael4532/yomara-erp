<?php

namespace App\Modules\Hebergement\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Renewal extends Model
{
    use BelongsToCompany;

    protected $table = 'hb_renewals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'amount' => 'float', 'cost' => 'float', 'paid_at' => 'datetime', 'provider_done_at' => 'datetime'];
    }
}
