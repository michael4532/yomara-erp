<?php

namespace App\Modules\Relances\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RecoveryCase extends Model
{
    use BelongsToCompany;

    protected $table = 'rc_cases';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opened_at' => 'date:Y-m-d', 'next_action_at' => 'date:Y-m-d', 'notice_deadline' => 'date:Y-m-d', 'closed_at' => 'datetime', 'amount_initial' => 'float', 'recovered' => 'float', 'fees' => 'float', 'written_off' => 'float', 'dunning_was_paused' => 'boolean'];
    }
}
