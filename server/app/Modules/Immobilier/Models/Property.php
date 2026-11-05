<?php

namespace App\Modules\Immobilier\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Property extends Model
{
    use BelongsToCompany;

    protected $table = 'im_properties';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['fee_rate' => 'float', 'active' => 'boolean'];
    }
}
