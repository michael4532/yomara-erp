<?php

namespace App\Modules\Sites\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class SiteMessage extends Model
{
    use BelongsToCompany;

    protected $table = 'ws_messages';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['consent' => 'boolean', 'read_at' => 'datetime'];
    }
}
