<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MessagingLog extends Model
{
    use BelongsToCompany;

    protected $table = 'messaging_log';

    protected $fillable = ['company_id', 'user_id', 'channel', 'provider', 'to', 'subject', 'status', 'provider_id', 'error', 'context'];
}
