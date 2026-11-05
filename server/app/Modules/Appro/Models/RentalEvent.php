<?php

namespace App\Modules\Appro\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RentalEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'ap_rental_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
