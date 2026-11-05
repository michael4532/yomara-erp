<?php

namespace App\Modules\Appro\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ImportCost extends Model
{
    use BelongsToCompany;

    protected $table = 'ap_import_costs';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }
}
