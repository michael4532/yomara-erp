<?php

namespace App\Modules\Appro\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Spec extends Model
{
    use BelongsToCompany;

    protected $table = 'ap_specs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deadline' => 'date:Y-m-d', 'sections' => 'array', 'requirements' => 'array', 'sent_to' => 'array'];
    }
}
