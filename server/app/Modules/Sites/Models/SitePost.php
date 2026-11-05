<?php

namespace App\Modules\Sites\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SitePost extends Model
{
    use BelongsToCompany;

    protected $table = 'ws_posts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['published' => 'boolean', 'published_at' => 'datetime'];
    }
}
