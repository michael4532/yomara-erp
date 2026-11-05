<?php

namespace App\Modules\Sites\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SiteOrder extends Model
{
    use BelongsToCompany;

    protected $table = 'ws_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'delivery_fee' => 'float', 'subtotal' => 'float', 'total' => 'float', 'discount' => 'float', 'review_requested_at' => 'datetime'];
    }
}
