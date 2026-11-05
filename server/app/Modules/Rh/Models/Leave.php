<?php

namespace App\Modules\Rh\Models;

use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Concerns\Syncable;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

class Leave extends Model
{
    use Syncable;

    protected $table = 'rh_leaves';

    public static string $syncEntity = 'rh_leaves';

    public static string $viewPermission = 'rh.view|rh.manage|rh.self';

    /** rh.self : le salarié dépose et annule ses propres demandes. */
    public static string $managePermission = 'rh.manage|rh.self';

    protected $fillable = ['employee_id', 'type', 'start_date', 'end_date', 'days', 'status', 'reason', 'decision_note'];

    public const TYPES = [
        'annual' => 'Congé annuel', 'sick' => 'Maladie', 'maternity' => 'Maternité', 'unpaid' => 'Absence non payée',
        'exceptional' => 'Permission exceptionnelle', 'other' => 'Autre',
    ];

    /** Congé créé par une demande (ticket) : la demande se charge des notifications. */
    public static bool $quiet = false;

    public const STATUSES = ['requested' => 'demandé', 'approved' => 'approuvé', 'rejected' => 'refusé', 'cancelled' => 'annulé'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'days' => 'float', 'decided_at' => 'datetime', 'decided_by' => 'integer', 'requested_by' => 'integer', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [
            'employee_id' => ['required', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', app('tenant')->id)],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'days' => 'nullable|numeric|min:0.5|max:366',
            'status' => 'nullable|in:requested,approved,rejected,cancelled',
            'reason' => 'nullable|string|max:255',
            'decision_note' => 'nullable|string|max:255',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('rh.view|rh.manage|paie.view|paie.manage')) {
            $q->whereIn('employee_id', Employee::where('user_id', $user->id)->select('id'));
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $manager = ! $user || $user->hasPermission('rh.manage');
        $status = $data['status'] ?? ($creating ? 'requested' : $this->status);
        $previous = $creating ? null : $this->status;

        if (! $manager) {
            $own = Employee::where('user_id', $user->id)->value('id');
            if (! $own) {
                throw new \DomainException('Votre compte n\'est relié à aucune fiche salarié : demandez au service RH de le faire.');
            }
            if (($data['employee_id'] ?? $this->employee_id) !== $own || (! $creating && $this->employee_id !== $own)) {
                throw new \DomainException('Vous ne pouvez gérer que vos propres demandes.');
            }
            if ($creating) {
                $status = 'requested';
            } elseif ($previous !== 'requested') {
                throw new \DomainException('Cette demande a déjà été traitée par le service RH.');
            } elseif (! in_array($status, ['requested', 'cancelled'], true)) {
                throw new \DomainException('Seul le service RH approuve ou refuse les demandes.');
            }
            unset($data['decision_note']);
        }
        $data['status'] = $status;

        $start = $data['start_date'] ?? $this->start_date?->toDateString();
        $end = $data['end_date'] ?? $this->end_date?->toDateString();
        if (empty($data['days']) && ($creating || isset($data['start_date']) || isset($data['end_date']))) {
            $data['days'] = self::workingDays($start, $end);
        }
        if ($data['days'] ?? $this->days) {
            if ((float) ($data['days'] ?? $this->days) <= 0) {
                throw new \DomainException('La période ne compte aucun jour ouvrable.');
            }
        }
        // Chevauchement avec un autre congé demandé ou approuvé
        if (in_array($status, ['requested', 'approved'], true)) {
            $overlap = static::where('employee_id', $data['employee_id'] ?? $this->employee_id)->where('id', '!=', $this->id ?? '')
                ->whereIn('status', ['requested', 'approved'])->whereDate('start_date', '<=', $end)->whereDate('end_date', '>=', $start)->first();
            if ($overlap) {
                throw new \DomainException('Une autre demande couvre déjà cette période (du '.$overlap->start_date->format('d/m/Y').' au '.$overlap->end_date->format('d/m/Y').').');
            }
        }

        if ($creating) {
            $this->requested_by = $user?->id;
        }
        if ($status !== $previous && in_array($status, ['approved', 'rejected'], true) || ($previous === 'approved' && $status === 'cancelled')) {
            $this->decided_by = $user?->id;
            $this->decided_at = now();
        }
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        $emp = $this->employee;
        $changed = $creating || $this->wasChanged('status');
        if (! $changed || ! $emp) {
            return;
        }
        $user = auth()->user();
        // Décision prise depuis l'onglet Congés : la demande (ticket) liée suit
        if (! $creating && in_array($this->status, ['approved', 'rejected', 'cancelled'], true)) {
            $req = EmployeeRequest::where('leave_id', $this->id)->whereNotIn('status', [$this->status])->first();
            if ($req) {
                $req->forceFill(['status' => $this->status, 'decision_note' => $req->decision_note ?? $this->decision_note,
                    'decided_by' => in_array($this->status, ['approved', 'rejected'], true) ? $user?->id : $req->decided_by,
                    'decided_at' => in_array($this->status, ['approved', 'rejected'], true) ? now() : $req->decided_at])->save();
                app(\App\Modules\Rh\RequestNotifier::class)->statusChanged($req);

                return;
            }
        }
        if (self::$quiet) {
            return;
        }
        $period = 'du '.$this->start_date->format('d/m/Y').' au '.$this->end_date->format('d/m/Y').' ('.rtrim(rtrim(number_format($this->days, 1, ',', ''), '0'), ',').' j)';
        ActivityLog::record('rh.leave.'.$this->status, $this, ['days' => $this->days]);

        if ($this->status === 'requested' && $creating && $user?->id === $emp->user_id) {
            // Demande du salarié → responsables RH
            $managers = User::where('company_id', $this->company_id)->where('is_active', true)->where('id', '!=', $user->id)->get()
                ->filter(fn ($u) => $u->hasPermission('rh.manage'))->pluck('id')->all();
            AppNotification::send($this->company_id, $managers, 'rh.leave', 'Demande de congé', $emp->full_name.' : '.(self::TYPES[$this->type] ?? $this->type).' '.$period, 'rh', $this->id);
        } elseif ($emp->user_id && $emp->user_id !== $user?->id && in_array($this->status, ['approved', 'rejected', 'cancelled'], true)) {
            AppNotification::send($this->company_id, [$emp->user_id], 'rh.leave', 'Congé '.self::STATUSES[$this->status],
                (self::TYPES[$this->type] ?? $this->type).' '.$period.($this->decision_note ? ' — '.$this->decision_note : ''), 'rh', $this->id);
        }
    }

    public function beforeEntityDelete(): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('rh.manage')) {
            throw new \DomainException('Annulez la demande au lieu de la supprimer.');
        }
    }

    /** Jours ouvrables (lundi → samedi) entre deux dates incluses. */
    public static function workingDays(?string $from, ?string $to): float
    {
        if (! $from || ! $to) {
            return 0;
        }
        $d = Carbon::parse($from);
        $end = Carbon::parse($to);
        $n = 0;
        while ($d->lte($end)) {
            if (! $d->isSunday()) {
                $n++;
            }
            $d->addDay();
        }

        return (float) $n;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }
}
