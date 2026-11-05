<?php

namespace App\Modules\Sites\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SiteCart extends Model
{
    use BelongsToCompany;

    protected $table = 'ws_carts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['items' => 'array', 'total' => 'float', 'reminded_at' => 'datetime', 'recovered_at' => 'datetime'];
    }
}
