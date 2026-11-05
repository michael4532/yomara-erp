<?php

namespace App\Modules\Tresorerie\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class StatementLine extends Model
{
    use BelongsToCompany;

    protected $table = 'tr_statement_lines';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'float', 'balance' => 'float', 'matched_at' => 'datetime'];
    }
}
