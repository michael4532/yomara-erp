<?php

namespace App\Modules\Immobilier\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Lease extends Model
{
    use BelongsToCompany;

    protected $table = 'im_leases';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'deposit_paid_at' => 'date:Y-m-d', 'next_revision_date' => 'date:Y-m-d', 'ended_at' => 'date:Y-m-d', 'rent' => 'float', 'charges' => 'float', 'tax_rate' => 'float', 'deposit_amount' => 'float', 'deposit_paid' => 'float', 'deposit_returned' => 'float', 'deposit_retained' => 'float', 'revision_rate' => 'float', 'months' => 'integer', 'due_day' => 'integer', 'advance_months' => 'integer'];
    }
}
