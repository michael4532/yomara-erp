<?php

namespace App\Modules\Paie\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payslip extends Model
{
    use BelongsToCompany;

    protected $table = 'paie_payslips';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['employee' => 'array', 'items' => 'array', 'result' => 'array', 'days_worked' => 'float', 'gross' => 'float', 'net' => 'float', 'cost' => 'float'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'run_id');
    }
}
