<?php

namespace App\Modules\Appro\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Rental extends Model
{
    use BelongsToCompany;

    protected $table = 'ap_rentals';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'returned_at' => 'date:Y-m-d', 'rate' => 'float', 'quantity' => 'float', 'deposit' => 'float', 'deposit_returned' => 'boolean', 'reminded_on' => 'date:Y-m-d'];
    }
}
