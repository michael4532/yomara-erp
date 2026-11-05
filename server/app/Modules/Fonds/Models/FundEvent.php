<?php

namespace App\Modules\Fonds\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class FundEvent extends Model
{
    use BelongsToCompany;

    protected $table = 'af_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
