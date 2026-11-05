<?php

namespace App\Modules\Appro\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ImportFile extends Model
{
    use BelongsToCompany;

    protected $table = 'ap_imports';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['exchange_rate' => 'float', 'vat_rate' => 'float', 'order_date' => 'date:Y-m-d', 'eta' => 'date:Y-m-d', 'arrived_at' => 'date:Y-m-d', 'applied_at' => 'datetime'];
    }
}
