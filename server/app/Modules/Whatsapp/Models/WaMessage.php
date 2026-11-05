<?php

namespace App\Modules\Whatsapp\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class WaMessage extends Model
{
    use BelongsToCompany;

    protected $table = 'wa_messages';

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['auto' => 'boolean', 'user_id' => 'integer', 'conversation_id' => 'integer'];
    }
}
