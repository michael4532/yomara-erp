<?php

namespace App\Modules\Appro\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ImportLine extends Model
{
    use BelongsToCompany;

    protected $table = 'ap_import_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'float', 'unit_price' => 'float', 'weight' => 'float', 'duty_rate' => 'float'];
    }
}
