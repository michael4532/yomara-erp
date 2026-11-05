<?php

namespace App\Modules\Tickets\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Report extends Model
{
    use BelongsToCompany;

    protected $table = 'tk_reports';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['data' => 'array', 'from' => 'date:Y-m-d', 'to' => 'date:Y-m-d'];
    }
}
