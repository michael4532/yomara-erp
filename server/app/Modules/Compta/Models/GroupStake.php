<?php

namespace App\Modules\Compta\Models;

use Illuminate\Database\Eloquent\Model;

/** Achat complémentaire ou cession partielle de titres d'une société du groupe. */
class GroupStake extends Model
{
    protected $table = 'compta_group_stakes';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'change_pct' => 'float', 'amount' => 'float', 'cost_basis' => 'float'];
    }
}
