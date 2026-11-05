<?php

namespace App\Modules\Interventions\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Contrat de maintenance : visites préventives périodiques, délai garanti, forfait. */
class Contract extends Model
{
    use Syncable;

    protected $table = 'it_contracts';

    public static string $syncEntity = 'it_contracts';

    public static string $viewPermission = 'interventions.view|interventions.do|interventions.manage';

    public static string $managePermission = 'interventions.manage';

    public static array $searchable = ['number', 'name'];

    public const FREQUENCIES = ['none' => 'Sans visite programmée', 'monthly' => 'Mensuelle', 'quarterly' => 'Trimestrielle', 'semiannual' => 'Semestrielle', 'annual' => 'Annuelle'];

    public const MONTHS = ['monthly' => 1, 'quarterly' => 3, 'semiannual' => 6, 'annual' => 12, 'none' => 0];

    protected $fillable = ['partner_id', 'name', 'start_date', 'end_date', 'frequency', 'next_visit', 'visit_minutes', 'response_hours', 'price', 'billing',
        'labor_included', 'parts_included', 'technician_id', 'status', 'notes'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'next_visit' => 'date:Y-m-d', 'invoiced_until' => 'date:Y-m-d', 'price' => 'float',
            'visit_minutes' => 'integer', 'response_hours' => 'integer', 'labor_included' => 'boolean', 'parts_included' => 'boolean', 'technician_id' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'partner_id' => ['required', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'frequency' => 'nullable|in:'.implode(',', array_keys(self::FREQUENCIES)),
            'next_visit' => 'nullable|date',
            'visit_minutes' => 'nullable|integer|min:15|max:1440',
            'response_hours' => 'nullable|integer|min:1|max:720',
            'price' => 'nullable|numeric|min:0',
            'billing' => 'nullable|in:monthly,quarterly,annual,none',
            'labor_included' => 'nullable|boolean',
            'parts_included' => 'nullable|boolean',
            'technician_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'status' => 'nullable|in:active,suspended,ended',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            $c->number ??= Sequence::next($c->company_id, 'it_contract', 'CTR');
            if (! $c->next_visit && ($c->frequency ?? 'quarterly') !== 'none') {
                $c->next_visit = $c->start_date;
            }
        });
    }

    public function isActiveOn(Carbon $d): bool
    {
        return $this->status === 'active' && $this->start_date->lte($d) && (! $this->end_date || $this->end_date->gte($d));
    }

    /** Prochaine date après une visite. */
    public function advance(Carbon $from): ?Carbon
    {
        $m = self::MONTHS[$this->frequency] ?? 0;

        return $m ? $from->copy()->addMonthsNoOverflow($m) : null;
    }
}
