<?php

namespace App\Modules\Sites\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SiteCoupon extends Model
{
    use BelongsToCompany;

    protected $table = 'ws_coupons';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'float', 'min_order' => 'float', 'starts_on' => 'date', 'ends_on' => 'date', 'once_per_customer' => 'boolean', 'active' => 'boolean'];
    }
}
