<?php

namespace App\Modules\Relances\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RecoveryAction extends Model
{
    use BelongsToCompany;

    protected $table = 'rc_actions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'promise_date' => 'date:Y-m-d', 'amount' => 'float', 'data' => 'array'];
    }
}
