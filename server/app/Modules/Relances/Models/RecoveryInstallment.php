<?php

namespace App\Modules\Relances\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class RecoveryInstallment extends Model
{
    use BelongsToCompany;

    protected $table = 'rc_installments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_date' => 'date:Y-m-d', 'amount' => 'float', 'paid' => 'float', 'alerted' => 'boolean'];
    }
}
