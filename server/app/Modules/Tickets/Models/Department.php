<?php

namespace App\Modules\Tickets\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use BelongsToCompany;

    protected $table = 'tk_departments';

    protected $guarded = ['id', 'company_id'];

    public const DEFAULT_SLA = ['critical' => 4, 'high' => 24, 'normal' => 72, 'low' => 168];

    protected function casts(): array
    {
        return ['supervisor_ids' => 'array', 'categories' => 'array', 'sla_hours' => 'array', 'accepts_tickets' => 'boolean', 'active' => 'boolean'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'department_id');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    public function slaHours(string $urgency): int
    {
        return (int) (($this->sla_hours ?? [])[$urgency] ?? self::DEFAULT_SLA[$urgency] ?? 72);
    }
}
