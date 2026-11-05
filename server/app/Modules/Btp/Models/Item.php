<?php

namespace App\Modules\Btp\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Item extends Model
{
    use BelongsToCompany;

    protected $table = 'btp_items';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_cost' => 'float', 'unit_price' => 'float', 'position' => 'integer'];
    }
}
