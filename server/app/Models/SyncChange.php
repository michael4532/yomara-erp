<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncChange extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['company_id', 'entity', 'record_id', 'op'];
}
