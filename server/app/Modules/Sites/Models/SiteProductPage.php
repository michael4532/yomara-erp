<?php

namespace App\Modules\Sites\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SiteProductPage extends Model
{
    use BelongsToCompany;

    protected $table = 'ws_product_pages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['photos' => 'array', 'related' => 'array', 'compare_at' => 'float'];
    }
}
