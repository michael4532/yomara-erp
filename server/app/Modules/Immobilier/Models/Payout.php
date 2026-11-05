<?php

namespace App\Modules\Immobilier\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Payout extends Model
{
    use BelongsToCompany;

    protected $table = 'im_payouts';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['period_from' => 'date:Y-m-d', 'period_to' => 'date:Y-m-d', 'paid_at' => 'date:Y-m-d', 'collected' => 'float', 'fees' => 'float', 'expenses' => 'float', 'amount' => 'float'];
    }
}
