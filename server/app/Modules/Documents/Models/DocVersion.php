<?php

namespace App\Modules\Documents\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class DocVersion extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'dc_versions';

    protected $fillable = ['file_id', 'number', 'path', 'name', 'mime', 'size', 'sha256', 'user_id', 'comment'];

    protected $hidden = ['path', 'company_id'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'size' => 'integer', 'created_at' => 'datetime'];
    }
}
