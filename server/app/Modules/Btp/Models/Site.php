<?php

namespace App\Modules\Btp\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    use BelongsToCompany;

    protected $table = 'btp_sites';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['contract_amount' => 'float', 'tax_rate' => 'float', 'retention_rate' => 'float', 'advance_rate' => 'float', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'reception_date' => 'date:Y-m-d'];
    }
}
