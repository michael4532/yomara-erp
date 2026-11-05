<?php

namespace App\Modules\Rh\Models;

use App\Models\ActivityLog;
use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Contrat enregistré par la RH (sans juriste) : type, prise de fonction, période d'essai, échéance,
 * salaire, heures par semaine. Le contrat en vigueur met la fiche salarié à jour.
 */
class Contract extends Model
{
    use Syncable;

    protected $table = 'rh_contracts';

    public static string $syncEntity = 'rh_contracts';

    public static string $viewPermission = 'rh.manage|rh.contracts|rh.self';

    public static string $managePermission = 'rh.manage';

    public const TYPES = ['CDI' => 'CDI', 'CDD' => 'CDD', 'stage' => 'Stage', 'apprentissage' => 'Apprentissage', 'prestation' => 'Prestation / consultant', 'journalier' => 'Journalier'];

    public const STATUSES = ['draft' => 'Brouillon', 'active' => 'En vigueur', 'ended' => 'Terminé', 'terminated' => 'Rompu'];

    /** Type de contrat de la fiche salarié correspondant. */
    public const EMPLOYEE_TYPE = ['CDI' => 'CDI', 'CDD' => 'CDD', 'stage' => 'stage', 'apprentissage' => 'stage', 'prestation' => 'consultant', 'journalier' => 'journalier'];

    protected $fillable = ['employee_id', 'type', 'start_date', 'end_date', 'trial_end_date', 'signed_on', 'salary', 'weekly_hours', 'position_id', 'status', 'end_reason', 'file_url', 'notes'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'trial_end_date' => 'date:Y-m-d', 'signed_on' => 'date:Y-m-d', 'salary' => 'float', 'weekly_hours' => 'float', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'employee_id' => ['required', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'trial_end_date' => 'nullable|date|after_or_equal:start_date',
            'signed_on' => 'nullable|date',
            'salary' => 'nullable|numeric|min:0',
            'weekly_hours' => 'nullable|numeric|min:0|max:84',
            'position_id' => ['nullable', 'uuid', Rule::exists('rh_positions', 'id')->where('company_id', $company)],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'end_reason' => 'nullable|string|max:200',
            'file_url' => 'nullable|string|max:500',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('rh.manage|rh.contracts')) {
            $q->whereIn('employee_id', Employee::where('user_id', $user->id)->select('id'));
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $type = $data['type'] ?? $this->type;
        $end = $data['end_date'] ?? $this->end_date?->toDateString();
        if (in_array($type, ['CDD', 'stage', 'apprentissage'], true) && ! $end && ($data['status'] ?? $this->status ?? 'active') !== 'draft') {
            throw new \DomainException('Un '.self::TYPES[$type].' a une date de fin.');
        }
        if ($type === 'CDI' && $end && ($data['status'] ?? $this->status) === 'active') {
            throw new \DomainException('Un CDI n\'a pas de date de fin : indiquez la rupture (statut « Rompu ») si le contrat s\'arrête.');
        }
        if (array_key_exists('status', $data) && $data['status'] === null) {
            $data['status'] = 'active';
        }
        if ($creating) {
            $data['status'] ??= 'active';
            $this->number = Sequence::next($this->company_id ?? app('tenant')->id, 'rh.contract', 'CT');
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        ActivityLog::record('rh.contract.'.($creating ? 'created' : $this->status), $this, ['number' => $this->number, 'type' => $this->type]);
        if ($this->status !== 'active') {
            return;
        }
        // Un seul contrat en vigueur : le précédent se termine, la fiche salarié suit le nouveau
        static::where('employee_id', $this->employee_id)->where('id', '!=', $this->id)->where('status', 'active')->get()
            ->each(fn ($c) => $c->forceFill(['status' => 'ended', 'end_date' => $c->end_date ?? $this->start_date->copy()->subDay()])->save());
        $e = Employee::find($this->employee_id);
        if (! $e) {
            return;
        }
        $first = static::where('employee_id', $e->id)->whereIn('status', ['active', 'ended'])->min('start_date');
        $fill = [
            'contract_type' => self::EMPLOYEE_TYPE[$this->type] ?? $e->contract_type,
            'hire_date' => $first ?? $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
        ];
        if ($this->salary > 0) {
            $fill['base_salary'] = $this->salary;
        }
        if ($this->weekly_hours) {
            $fill['weekly_hours'] = $this->weekly_hours;
        }
        if ($this->position_id) {
            $fill['position_id'] = $this->position_id;
        }
        if (in_array($this->type, ['stage', 'apprentissage'], true) && $e->staff_type === 'employee') {
            $fill['staff_type'] = $this->type === 'stage' ? 'intern' : 'apprentice';
        }
        $e->forceFill($fill)->save();
    }

    public function beforeEntityDelete(): void
    {
        if ($this->status !== 'draft') {
            throw new \DomainException('Seul un brouillon se supprime : terminez ou rompez le contrat.');
        }
    }
}
