<?php

namespace App\Modules\Approbations\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Decision extends Model
{
    use BelongsToCompany;

    protected $table = 'wf_decisions';

    protected $fillable = ['request_id', 'step', 'user_id', 'on_behalf_of', 'decision', 'comment'];

    protected function casts(): array
    {
        return ['step' => 'integer', 'user_id' => 'integer', 'on_behalf_of' => 'integer'];
    }
}
