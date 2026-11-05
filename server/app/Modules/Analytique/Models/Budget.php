<?php

namespace App\Modules\Analytique\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Budget extends Model
{
    use BelongsToCompany;

    protected $table = 'bg_budgets';

    protected $fillable = ['name', 'start_date', 'end_date', 'status', 'warn_percent', 'notes'];

    protected $attributes = ['status' => 'draft', 'warn_percent' => 80];

    protected $hidden = ['company_id'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'warn_percent' => 'integer', 'approved_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('sort')->orderBy('id');
    }

    /** Mois couverts : ['2026-01', …]. */
    public function months(): array
    {
        $out = [];
        $m = $this->start_date->copy()->startOfMonth();
        while ($m->lte($this->end_date) && count($out) < 36) {
            $out[] = $m->format('Y-m');
            $m->addMonth();
        }

        return $out;
    }
}
