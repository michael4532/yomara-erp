<?php

namespace App\Modules\Sites\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    use BelongsToCompany;

    protected $table = 'ws_sites';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['theme' => 'array', 'content' => 'array', 'sections' => 'array', 'seo' => 'array', 'domain_checked_at' => 'datetime', 'published_at' => 'datetime'];
    }
}
