<?php

namespace App\Modules\Appro\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Comparison extends Model
{
    use BelongsToCompany;

    protected $table = 'ap_comparisons';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deadline' => 'date:Y-m-d', 'lines' => 'array', 'offers' => 'array'];
    }
}
