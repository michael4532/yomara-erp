<?php

namespace App\Modules\Rh\Models;

use App\Models\ActivityLog;
use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use App\Modules\Rh\RequestNotifier;
use App\Services\EntityWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Demande d'un salarié traitée comme un ticket (congé, augmentation, avance, démission, formation…).
 * Le salarié la crée depuis son espace (droit rh.request), le service RH la traite ; le responsable direct
 * la voit et reçoit les e-mails en copie.
 */
class EmployeeRequest extends Model
{
    use Syncable;

    protected $table = 'rh_requests';

    public static string $syncEntity = 'rh_requests';

    public static string $viewPermission = 'rh.view|rh.manage|rh.self|rh.request';

    public static string $managePermission = 'rh.manage|rh.request';

    public const TYPES = [
        'leave' => 'Congé',
        'sick' => 'Arrêt maladie',
        'absence' => 'Absence ou permission',
        'raise' => 'Augmentation de salaire',
        'advance' => 'Avance sur salaire',
        'resignation' => 'Démission',
        'training' => 'Formation',
        'schedule' => 'Horaires ou télétravail',
        'transfer' => 'Mutation ou changement de poste',
        'equipment' => 'Matériel ou équipement',
        'complaint' => 'Réclamation',
        'other' => 'Autre demande',
    ];

    /** Demandes qui créent un congé / une absence (dates). */
    public const LEAVE_TYPES = ['leave' => 'annual', 'sick' => 'sick', 'absence' => 'exceptional'];

    public const STATUSES = ['submitted' => 'Envoyée', 'in_review' => 'En cours d\'examen', 'approved' => 'Acceptée', 'rejected' => 'Refusée', 'cancelled' => 'Annulée'];

    protected $fillable = ['employee_id', 'type', 'subject', 'body', 'amount', 'start_date', 'end_date', 'leave_type', 'effective_date', 'status', 'decision_note', 'cc_user_ids'];

    protected function casts(): array
    {
        return [
            'amount' => 'float', 'start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'effective_date' => 'date:Y-m-d',
            'messages' => 'array', 'cc_user_ids' => 'array', 'attachments' => 'array', 'decided_at' => 'datetime', 'requested_by' => 'integer', 'decided_by' => 'integer', 'version' => 'integer',
        ];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'employee_id' => ['required', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'type' => ['required', Rule::in(array_keys(self::TYPES))],
            'subject' => 'nullable|string|max:200',
            'body' => 'nullable|string|max:10000',
            'amount' => 'nullable|numeric|min:0',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'leave_type' => ['nullable', Rule::in(array_keys(Leave::TYPES))],
            'effective_date' => 'nullable|date',
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'decision_note' => 'nullable|string|max:1000',
            'cc_user_ids' => 'nullable|array|max:10',
            'cc_user_ids.*' => ['integer', Rule::exists('users', 'id')->where('company_id', $company)],
        ];
    }

    /** Types proposés aux salariés (réglage de la société, tous par défaut). */
    public static function enabledTypes(): array
    {
        $set = data_get(app('tenant')->settings, 'rh_requests.types');

        return is_array($set) && $set ? array_values(array_intersect(array_keys(self::TYPES), $set)) : array_keys(self::TYPES);
    }

    /** RH : tout. Salarié : ses demandes et celles de son équipe (s'il est responsable). */
    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('rh.view|rh.manage')) {
            $mine = Employee::where('user_id', $user->id)->select('id');
            $q->where(fn ($w) => $w->whereIn('employee_id', $mine)->orWhereIn('manager_id', Employee::where('user_id', $user->id)->select('id')));
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $hr = ! $user || $user->hasPermission('rh.manage');
        $previous = $creating ? null : $this->status;
        $status = $data['status'] ?? ($creating ? 'submitted' : $this->status);

        if (! $hr) {
            if (! $creating) {
                unset($data['cc_user_ids']);
            }
            if (! $user->hasPermission('rh.request')) {
                throw new \DomainException('Les demandes depuis votre espace ne sont pas autorisées : voyez avec l\'administrateur.');
            }
            $own = Employee::where('user_id', $user->id)->value('id');
            if (! $own) {
                throw new \DomainException('Votre compte n\'est relié à aucune fiche salarié : demandez au service RH de le faire.');
            }
            if (($data['employee_id'] ?? $this->employee_id) !== $own || (! $creating && $this->employee_id !== $own)) {
                throw new \DomainException('Vous ne pouvez gérer que vos propres demandes.');
            }
            if ($creating) {
                $status = 'submitted';
            } elseif (! in_array($previous, ['submitted', 'in_review'], true)) {
                throw new \DomainException('Cette demande a déjà été traitée.');
            } elseif ($status !== $previous && $status !== 'cancelled') {
                throw new \DomainException('Seul le service RH accepte ou refuse une demande.');
            } elseif ($status === $previous) {
                // Une demande envoyée ne se réécrit pas : on ajoute un message au fil
                throw new \DomainException('Ajoutez plutôt un message à la demande.');
            }
            unset($data['decision_note']);
        }

        $type = $data['type'] ?? $this->type;
        if ($creating) {
            if (! $hr && ! in_array($type, self::enabledTypes(), true)) {
                throw new \DomainException('Ce type de demande n\'est pas proposé par votre entreprise.');
            }
            $data['subject'] = trim((string) ($data['subject'] ?? '')) ?: self::TYPES[$type];
            if (isset(self::LEAVE_TYPES[$type])) {
                if (empty($data['start_date']) || empty($data['end_date'])) {
                    throw new \DomainException($type === 'sick' ? 'Indiquez les dates de l\'arrêt maladie.' : 'Indiquez les dates du congé ou de l\'absence.');
                }
                $data['leave_type'] = $type === 'leave' ? ($data['leave_type'] ?? 'annual') : self::LEAVE_TYPES[$type];
            }
            if ($type === 'advance' && empty($data['amount'])) {
                throw new \DomainException('Indiquez le montant de l\'avance demandée.');
            }
            if ($type === 'resignation' && empty($data['effective_date'])) {
                throw new \DomainException('Indiquez votre dernier jour de travail souhaité.');
            }
            $emp = Employee::find($data['employee_id']);
            $this->manager_id = $emp ? RequestNotifier::managerOf($emp)?->id : null;
            $this->requested_by = $user?->id;
            $this->number = Sequence::next($this->company_id ?? app('tenant')->id, 'rh.request', 'DEM');
            $this->messages = [];
            $this->attachments = [];

            // Congé : la demande crée le congé (mêmes contrôles : chevauchement, jours ouvrables)
            if (isset(self::LEAVE_TYPES[$type])) {
                $leaveId = (string) Str::uuid();
                Leave::$quiet = true; // la demande prévient déjà la RH
                $r = app(EntityWriter::class)->upsert(Leave::class, $leaveId, [
                    'employee_id' => $data['employee_id'], 'type' => $data['leave_type'] ?? 'annual', 'start_date' => $data['start_date'], 'end_date' => $data['end_date'],
                    'reason' => Str::limit(trim((string) ($data['body'] ?? '')) ?: $data['subject'], 250, ''), 'status' => 'requested',
                ]);
                Leave::$quiet = false;
                if ($r['status'] !== EntityWriter::APPLIED) {
                    throw new \DomainException($r['message'] ?? 'Congé refusé.');
                }
                $this->leave_id = $leaveId;
            }
        }

        if ($status !== $previous && in_array($status, ['approved', 'rejected'], true)) {
            $this->decided_by = $user?->id;
            $this->decided_at = now();
        }
        $data['status'] = $status;
    }

    public function afterEntityWrite(array $data, bool $creating): void
    {
        $changed = ! $creating && $this->wasChanged('status');
        if (! $creating && ! $changed) {
            return;
        }
        ActivityLog::record('rh.request.'.($creating ? 'submitted' : $this->status), $this, ['number' => $this->number, 'type' => $this->type]);

        // Le congé lié suit la décision prise sur la demande
        if ($changed && $this->leave_id && in_array($this->status, ['approved', 'rejected', 'cancelled'], true)) {
            $leave = Leave::find($this->leave_id);
            if ($leave && $leave->status !== $this->status && ! ($leave->status === 'requested' && $this->status === 'in_review')) {
                app(EntityWriter::class)->upsert(Leave::class, $leave->id, ['status' => $this->status, 'decision_note' => $this->decision_note ? Str::limit($this->decision_note, 250, '') : null]);
            }
        }

        app(RequestNotifier::class)->{$creating ? 'submitted' : 'statusChanged'}($this);
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une demande ne se supprime pas : annulez-la.');
    }

    /** Les appareils ne reçoivent pas le chemin de stockage des justificatifs. */
    public function toSyncArray(): array
    {
        $a = $this->toArray();
        $a['attachments'] = array_map(fn ($x) => array_diff_key((array) $x, ['path' => 1]), (array) ($a['attachments'] ?? []));

        return $a;
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }
}
