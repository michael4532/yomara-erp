<?php

namespace App\Modules\Relances\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Reminder extends Model
{
    use BelongsToCompany;

    protected $table = 'rl_reminders';

    protected $fillable = ['partner_id', 'level', 'level_name', 'documents', 'channels', 'status', 'manual', 'user_id'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['documents' => 'array', 'channels' => 'array', 'manual' => 'boolean', 'level' => 'integer'];
    }
}
