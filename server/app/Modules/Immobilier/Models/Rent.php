<?php

namespace App\Modules\Immobilier\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Rent extends Model
{
    use BelongsToCompany;

    protected $table = 'im_rents';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'rent' => 'float', 'charges' => 'float', 'total' => 'float', 'reminded_at' => 'datetime'];
    }
}
