<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Template extends Model
{
    use BelongsToCompany;

    protected $table = 'mk_templates';

    protected $fillable = ['name', 'channel', 'subject', 'preheader', 'blocks', 'html', 'text', 'style'];

    protected function casts(): array
    {
        return ['blocks' => 'array', 'style' => 'array'];
    }
}
