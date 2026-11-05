<?php

namespace App\Modules\Immobilisations\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class AssetLine extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $table = 'im_lines';

    protected $fillable = ['asset_id', 'seq', 'period_start', 'period_end', 'amount', 'cumulated', 'nbv', 'kind', 'posted_at'];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'amount' => 'float', 'cumulated' => 'float', 'nbv' => 'float', 'posted_at' => 'datetime', 'seq' => 'integer'];
    }
}
