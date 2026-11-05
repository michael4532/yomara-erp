<?php

namespace App\Modules\Immobilier\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    use BelongsToCompany;

    protected $table = 'im_expenses';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'float', 'owner_charge' => 'boolean'];
    }
}
