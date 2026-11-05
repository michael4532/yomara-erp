<?php

namespace App\Modules\Btp\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Log extends Model
{
    use BelongsToCompany;

    protected $table = 'btp_logs';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'workers' => 'integer'];
    }
}
