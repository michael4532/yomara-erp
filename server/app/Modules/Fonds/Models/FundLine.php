<?php

namespace App\Modules\Fonds\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FundLine extends Model
{
    use BelongsToCompany;

    protected $table = 'af_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_amount' => 'float', 'amount' => 'float', 'approved_quantity' => 'float', 'approved_amount' => 'float',
            'provided_quantity' => 'float', 'provided_amount' => 'float'];
    }
}
