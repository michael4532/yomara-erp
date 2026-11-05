<?php

namespace App\Modules\TableauxExcel\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Workbook extends Model
{
    use BelongsToCompany;

    protected $table = 'xl_workbooks';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['recipients' => 'array', 'protect' => 'boolean', 'year' => 'integer', 'last_generated_at' => 'datetime', 'last_sent_at' => 'datetime', 'downloads' => 'integer'];
    }
}
