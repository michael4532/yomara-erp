<?php

namespace App\Modules\Marketing\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Click extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'mk_clicks';

    protected $fillable = ['company_id', 'message_id', 'campaign_id', 'automation_id', 'url'];
}
