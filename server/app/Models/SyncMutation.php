<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncMutation extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'company_id', 'user_id', 'entity', 'record_id', 'status', 'message'];
}
