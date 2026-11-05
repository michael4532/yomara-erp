<?php

namespace App\Modules\Sites\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SiteRequest extends Model
{
    use BelongsToCompany;

    protected $table = 'ws_requests';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['needs' => 'array', 'quote' => 'array', 'estimate' => 'float', 'decided_at' => 'datetime'];
    }
}
