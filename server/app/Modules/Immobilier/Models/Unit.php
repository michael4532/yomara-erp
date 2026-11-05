<?php

namespace App\Modules\Immobilier\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    use BelongsToCompany;

    protected $table = 'im_units';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['rent' => 'float', 'charges' => 'float', 'surface' => 'float', 'rooms' => 'integer'];
    }
}
