<?php

namespace App\Modules\Migration\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MigrationJob extends Model
{
    use BelongsToCompany;

    protected $table = 'mig_jobs';

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted:array', 'files' => 'array', 'options' => 'array', 'progress' => 'array', 'counts' => 'array', 'warnings' => 'array',
            'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
