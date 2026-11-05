<?php

namespace App\Modules\Analytique\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class BudgetLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'bg_lines';

    protected $fillable = ['budget_id', 'kind', 'section_id', 'account_prefix', 'label', 'owner_id', 'amounts', 'total', 'sort'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['amounts' => 'array', 'total' => 'float', 'owner_id' => 'integer', 'alerted' => 'integer', 'sort' => 'integer'];
    }
}
