<?php

namespace App\Modules\Sites\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SiteReview extends Model
{
    use BelongsToCompany;

    protected $table = 'ws_reviews';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['products' => 'array', 'replied_at' => 'datetime'];
    }
}
