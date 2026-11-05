<?php

namespace App\Modules\Rh\Models;

use App\Models\ActivityLog;
use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

class Employee extends Model
{
    use \App\Models\Concerns\HasCustomFields, Syncable;

    protected $table = 'rh_employees';

    public static string $syncEntity = 'rh_employees';

    /** rh.self : le salarié ne reçoit que sa propre fiche (voir syncVisibility). */
    public static string $viewPermission = 'rh.view|rh.manage|rh.self';

    public static string $managePermission = 'rh.manage';

    protected $fillable = [
        'user_id', 'matricule', 'first_name', 'last_name', 'gender', 'birth_date', 'phone', 'email', 'address', 'emergency_contact', 'id_number',
        'position_id', 'manager_id', 'job_title', 'department', 'category', 'hire_date', 'end_date', 'contract_type', 'base_salary', 'transport_allowance', 'fixed_items',
        'marital_status', 'children_count', 'spouse_dependent', 'parts_override', 'cnps_number', 'is_expatriate', 'payment_method', 'payment_account',
        'leave_days_per_month', 'leave_opening_balance', 'notes', 'active',
        'department_id', 'staff_type', 'cnps_declared', 'cmu_enrolled', 'has_insurance', 'insurance_name', 'insurance_employee', 'insurance_employer', 'weekly_hours', 'work_schedule',
    ];

    /** Statut du personnel. */
    public const STAFF_TYPES = ['employee' => 'Salarié', 'intern' => 'Stagiaire', 'apprentice' => 'Apprenti', 'contractor' => 'Prestataire / consultant', 'daily' => 'Journalier'];

    /** Champs que le salarié peut corriger lui-même depuis son espace. */
    public const SELF_FIELDS = ['phone', 'email', 'address', 'emergency_contact'];

    protected $appends = ['full_name', 'tax_parts', 'hourly_rate', 'seniority_months'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date:Y-m-d', 'hire_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d',
            'base_salary' => 'float', 'transport_allowance' => 'float', 'fixed_items' => 'array', 'settlement' => 'array',
            'children_count' => 'integer', 'spouse_dependent' => 'boolean', 'parts_override' => 'float', 'user_id' => 'integer',
            'is_expatriate' => 'boolean', 'active' => 'boolean', 'leave_days_per_month' => 'float', 'leave_opening_balance' => 'float', 'version' => 'integer',
            'cnps_declared' => 'boolean', 'cmu_enrolled' => 'boolean', 'has_insurance' => 'boolean', 'insurance_employee' => 'float', 'insurance_employer' => 'float',
            'weekly_hours' => 'float', 'work_schedule' => 'array',
        ];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('company_id', $company)],
            'matricule' => 'nullable|string|max:30',
            'first_name' => 'required|string|max:120',
            'last_name' => 'required|string|max:120',
            'gender' => 'nullable|in:M,F',
            'birth_date' => 'nullable|date',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'emergency_contact' => 'nullable|string|max:255',
            'id_number' => 'nullable|string|max:60',
            'position_id' => ['nullable', 'uuid', Rule::exists('rh_positions', 'id')->where('company_id', $company)],
            'manager_id' => ['nullable', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'job_title' => 'nullable|string|max:255',
            'department' => 'nullable|string|max:255',
            'category' => 'nullable|string|max:30',
            'hire_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'contract_type' => 'nullable|in:CDI,CDD,stage,journalier,consultant',
            'base_salary' => 'nullable|numeric|min:0',
            'transport_allowance' => 'nullable|numeric|min:0',
            'fixed_items' => 'nullable|array',
            'fixed_items.*.label' => 'required|string|max:120',
            'fixed_items.*.amount' => 'required|numeric',
            'fixed_items.*.taxable' => 'nullable|boolean',
            'fixed_items.*.social' => 'nullable|boolean',
            'marital_status' => 'nullable|in:single,married,divorced,widowed',
            'children_count' => 'nullable|integer|min:0|max:30',
            'spouse_dependent' => 'nullable|boolean',
            'parts_override' => 'nullable|numeric|min:1|max:5',
            'cnps_number' => 'nullable|string|max:40',
            'is_expatriate' => 'nullable|boolean',
            'payment_method' => 'nullable|in:bank_transfer,cash,mobile_money,cheque',
            'payment_account' => 'nullable|string|max:80',
            'leave_days_per_month' => 'nullable|numeric|min:0|max:5',
            'leave_opening_balance' => 'nullable|numeric|min:-100|max:365',
            'notes' => 'nullable|string|max:5000',
            'active' => 'nullable|boolean',
            'department_id' => ['nullable', 'uuid', Rule::exists('rh_departments', 'id')->where('company_id', $company)],
            'staff_type' => ['nullable', Rule::in(array_keys(self::STAFF_TYPES))],
            'cnps_declared' => 'nullable|boolean',
            'cmu_enrolled' => 'nullable|boolean',
            'has_insurance' => 'nullable|boolean',
            'insurance_name' => 'nullable|string|max:120',
            'insurance_employee' => 'nullable|numeric|min:0',
            'insurance_employer' => 'nullable|numeric|min:0',
            'weekly_hours' => 'nullable|numeric|min:0|max:84',
            'work_schedule' => 'nullable|array',
            'work_schedule.*' => 'nullable|array',
            'work_schedule.*.start' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'work_schedule.*.end' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'work_schedule.*.break' => 'nullable|integer|min:0|max:300',
        ];
    }

    /** Ce que chaque utilisateur reçoit en synchronisation. */
    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('rh.view|rh.manage|paie.view|paie.manage')) {
            $q->where('user_id', $user->id);
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        // Valeurs par défaut (les colonnes NOT NULL ne reçoivent pas null)
        foreach (['base_salary' => 0, 'transport_allowance' => 0, 'children_count' => 0, 'leave_days_per_month' => 2.2, 'leave_opening_balance' => 0,
            'contract_type' => 'CDI', 'marital_status' => 'single', 'payment_method' => 'bank_transfer', 'spouse_dependent' => false, 'is_expatriate' => false, 'active' => true] as $k => $v) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = $v;
            }
        }
        if (array_key_exists('matricule', $data) && blank($data['matricule'])) {
            unset($data['matricule']);
        }
        if ($creating && empty($data['matricule'])) {
            $data['matricule'] = 'EMP'.substr(Sequence::next($this->company_id ?? app('tenant')->id, 'rh.matricule', 'EMP', 0), -5);
        }
        if (! empty($data['matricule']) && static::where('matricule', $data['matricule'])->where('id', '!=', $this->id ?? '')->exists()) {
            throw new \DomainException('Le matricule '.$data['matricule'].' est déjà attribué.');
        }
        if (! empty($data['user_id']) && static::where('user_id', $data['user_id'])->where('id', '!=', $this->id ?? '')->exists()) {
            throw new \DomainException('Ce compte utilisateur est déjà relié à un autre salarié.');
        }
        foreach (['cnps_declared' => true, 'cmu_enrolled' => true, 'has_insurance' => false, 'insurance_employee' => 0, 'insurance_employer' => 0, 'weekly_hours' => 40, 'staff_type' => 'employee'] as $k => $v) {
            if (array_key_exists($k, $data) && $data[$k] === null) {
                $data[$k] = $v;
            }
        }
        // Stagiaire, prestataire : non déclarés par défaut (payés au montant convenu, sans retenues)
        if ($creating && in_array($data['staff_type'] ?? 'employee', ['intern', 'contractor'], true)) {
            $data['cnps_declared'] ??= false;
            $data['cmu_enrolled'] ??= false;
        }
        // Département : le nom du service suit le département choisi
        if (array_key_exists('department_id', $data)) {
            if ($data['department_id']) {
                $data['department'] = Department::whereKey($data['department_id'])->value('name') ?? ($data['department'] ?? null);
            }
        } elseif (! empty($data['department']) && ! ($this->department_id ?? null)) {
            $data['department_id'] = Department::whereRaw('lower(name) = ?', [mb_strtolower(trim($data['department']))])->value('id');
        }
        // Poste choisi : fonction, service et catégorie repris de la fiche de poste s'ils ne sont pas saisis
        if (! empty($data['position_id'])) {
            $pos = Position::find($data['position_id']);
            if ($pos) {
                foreach (['job_title' => $pos->title, 'department' => $pos->department] as $k => $v) {
                    if (blank($data[$k] ?? $this->{$k}) && $v) {
                        $data[$k] = $v;
                    }
                }
                if (blank($data['category'] ?? $this->category) && $pos->category_id) {
                    $data['category'] = Category::whereKey($pos->category_id)->value('code');
                }
            }
        }
        if (! empty($data['department']) && empty($data['department_id']) && ! ($this->department_id ?? null)) {
            $data['department_id'] = Department::whereRaw('lower(name) = ?', [mb_strtolower(trim($data['department']))])->value('id');
        }
        if (! empty($data['manager_id']) && $this->id && $data['manager_id'] === $this->id) {
            throw new \DomainException('Un salarié ne peut pas être son propre responsable.');
        }
        $hire = $data['hire_date'] ?? $this->hire_date?->toDateString();
        $end = $data['end_date'] ?? $this->end_date?->toDateString();
        if ($hire && $end && $end < $hire) {
            throw new \DomainException('La date de sortie précède la date d\'embauche.');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        ActivityLog::record($creating ? 'rh.employee.created' : 'rh.employee.updated', $this, ['name' => $this->full_name]);
        // Compte relié ou changé : ses congés doivent partir vers l'appareil du salarié.
        if (! $creating && $this->wasChanged('user_id')) {
            $this->leaves()->get()->each->logSyncChange('upsert');
        }
    }

    public function beforeEntityDelete(): void
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('paie_payslips') && \Illuminate\Support\Facades\DB::table('paie_payslips')->where('employee_id', $this->id)->exists()) {
            throw new \DomainException('Ce salarié a des bulletins de paie : indiquez plutôt sa date de sortie.');
        }
    }

    /** Heures par mois (semaine × 52 / 12) et taux horaire du salaire de base. */
    public function getHourlyRateAttribute(): ?float
    {
        $hours = (float) ($this->weekly_hours ?: 40) * 52 / 12;

        return $hours > 0 && $this->base_salary > 0 ? round((float) $this->base_salary / $hours, 2) : null;
    }

    /** Ancienneté en mois (depuis la première embauche). */
    public function getSeniorityMonthsAttribute(): ?int
    {
        return $this->hire_date ? (int) $this->hire_date->diffInMonths($this->end_date && $this->end_date->isPast() ? $this->end_date : now()) : null;
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->last_name.' '.$this->first_name);
    }

    /**
     * Nombre de parts fiscales (ITS) : 1 célibataire, 2 marié, +0,5 par enfant à charge, plafond 5.
     * Les cas particuliers (veuf avec enfants, divorcé avec enfants…) se règlent par parts_override.
     */
    public function getTaxPartsAttribute(): float
    {
        if ($this->parts_override) {
            return (float) $this->parts_override;
        }
        // Code général des impôts (CI) : marié 2 parts ; célibataire ou divorcé avec enfants : le 1er enfant compte une part entière ;
        // veuf avec enfants comme un marié ; +0,5 par enfant.
        $children = (int) $this->children_count;
        $parts = match (true) {
            $this->marital_status === 'married', $this->marital_status === 'widowed' && $children > 0 => 2.0,
            $children > 0 => 1.5,
            default => 1.0,
        };
        $parts += 0.5 * $children;

        return min(5.0, $parts);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class, 'employee_id');
    }
}
