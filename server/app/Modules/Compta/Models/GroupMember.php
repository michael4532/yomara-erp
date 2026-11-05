<?php

namespace App\Modules\Compta\Models;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

/** Société du périmètre de consolidation (lien entre deux sociétés : pas de portée par société courante). */
class GroupMember extends Model
{
    protected $table = 'compta_group_members';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['interest_pct' => 'float', 'control_pct' => 'float', 'investment_cost' => 'float', 'equity_at_acquisition' => 'float', 'rate' => 'float', 'average_rate' => 'float', 'historical_rate' => 'float', 'goodwill_impairment' => 'float', 'goodwill_years' => 'integer', 'goodwill_impairment_date' => 'date:Y-m-d', 'exit_date' => 'date:Y-m-d', 'exit_proceeds' => 'float', 'acquisition_date' => 'date:Y-m-d'];
    }

    public function member()
    {
        return $this->belongsTo(Company::class, 'member_id');
    }

    public function stakes()
    {
        return $this->hasMany(GroupStake::class, 'group_member_id')->orderBy('date')->orderBy('id');
    }

    /**
     * Pourcentage d'intérêts et coût des titres chez la mère à une date (achats et cessions de titres inclus).
     * Le coût des titres cédés est, à défaut de saisie, la quote-part du coût moyen.
     */
    public function stakeAt(string $date): array
    {
        $pct = $this->interest_pct;
        $cost = $this->investment_cost;
        foreach ($this->stakes as $s) {
            if ($s->date->toDateString() > $date) {
                break;
            }
            if ($s->change_pct >= 0) {
                $cost += $s->amount;
            } else {
                $cost -= $s->cost_basis ?? ($pct > 0 ? $cost * min(1, -$s->change_pct / $pct) : 0);
            }
            $pct += $s->change_pct;
        }

        return [max(0, min(100, $pct)), round(max(0, $cost), 2)];
    }

    /** Coût des titres cédés lors d'une cession partielle (saisi ou quote-part du coût moyen). */
    public function costBasisOf(GroupStake $s): float
    {
        if ($s->cost_basis !== null) {
            return $s->cost_basis;
        }
        [$pct, $cost] = $this->stakeAt($s->date->copy()->subDay()->toDateString());

        return round($pct > 0 ? $cost * min(1, -$s->change_pct / $pct) : 0, 2);
    }

    public function parent()
    {
        return $this->belongsTo(Company::class, 'parent_id');
    }
}
