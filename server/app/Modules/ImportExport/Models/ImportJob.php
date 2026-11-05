<?php

namespace App\Modules\ImportExport\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ImportJob extends Model
{
    use BelongsToCompany;

    protected $table = 'ie_jobs';

    protected $guarded = ['id'];

    protected $hidden = ['path'];

    protected function casts(): array
    {
        return ['headers' => 'array', 'mapping' => 'array', 'options' => 'array', 'errors' => 'array'];
    }
}
