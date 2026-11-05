<?php

namespace App\Modules\Compta\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Journal extends Model
{
    use BelongsToCompany;

    protected $table = 'compta_journals';

    protected $fillable = ['company_id', 'code', 'name', 'type'];
}
