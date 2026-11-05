<?php

namespace App\Modules\Btp\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Situation extends Model
{
    use BelongsToCompany;

    protected $table = 'btp_situations';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'progress' => 'array', 'works_cumul' => 'float', 'works_previous' => 'float', 'works_amount' => 'float', 'advance_repaid' => 'float', 'retention' => 'float', 'tax' => 'float', 'net' => 'float', 'number' => 'integer'];
    }
}
