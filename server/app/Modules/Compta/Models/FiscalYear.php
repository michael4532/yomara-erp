<?php

namespace App\Modules\Compta\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    use BelongsToCompany;

    protected $table = 'compta_years';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fiscal' => 'array', 'allocation' => 'array', 'allocation_date' => 'date:Y-m-d', 'closed_at' => 'datetime', 'result' => 'float', 'year' => 'integer'];
    }
}
