<?php

namespace App\Modules\Tresorerie\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Statement extends Model
{
    use BelongsToCompany;

    protected $table = 'tr_statements';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date_from' => 'date:Y-m-d', 'date_to' => 'date:Y-m-d'];
    }
}
