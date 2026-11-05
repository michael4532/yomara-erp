<?php

namespace App\Modules\Paie\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    use BelongsToCompany;

    protected $table = 'paie_runs';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['totals' => 'array', 'payment_date' => 'date:Y-m-d', 'validated_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class, 'run_id');
    }
}
