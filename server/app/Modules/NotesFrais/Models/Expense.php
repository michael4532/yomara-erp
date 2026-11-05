<?php

namespace App\Modules\NotesFrais\Models;

use App\Models\ActivityLog;
use App\Models\AppNotification;
use App\Models\Concerns\Syncable;
use App\Models\User;
use App\Modules\NotesFrais\NotesFraisService;
use App\Modules\Rh\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

/**
 * Dépense d'un salarié : brouillon → soumise → approuvée (écriture de charge) → remboursée.
 * Le salarié la saisit hors ligne ; le justificatif part dès que le réseau revient.
 */
class Expense extends Model
{
    use Syncable;

    protected $table = 'nf_expenses';

    public static string $syncEntity = 'nf_expenses';

    public static string $viewPermission = 'notes_frais.self|notes_frais.approve|notes_frais.manage';

    public static string $managePermission = 'notes_frais.self|notes_frais.approve|notes_frais.manage';

    protected $fillable = ['employee_id', 'category_id', 'date', 'amount', 'description', 'merchant', 'project', 'project_id', 'status', 'decision_note', 'analytic'];

    protected $hidden = ['receipt_path'];

    protected $appends = ['has_receipt', 'over_limit', 'employee_name'];

    public const STATUSES = ['draft' => 'Brouillon', 'submitted' => 'À valider', 'approved' => 'Approuvée', 'rejected' => 'Refusée', 'paid' => 'Remboursée'];

    /** Champs du salarié : figés dès que la dépense est approuvée. */
    public const CONTENT = ['employee_id', 'category_id', 'date', 'amount', 'description', 'merchant', 'project', 'project_id'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'amount' => 'float', 'submitted_at' => 'datetime', 'decided_at' => 'datetime', 'paid_at' => 'date:Y-m-d',
            'submitted_by' => 'integer', 'decided_by' => 'integer', 'version' => 'integer', 'analytic' => 'array'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'employee_id' => ['required', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'category_id' => ['nullable', 'uuid', Rule::exists('nf_categories', 'id')->where('company_id', $company)],
            'date' => 'required|date',
            'amount' => 'required|numeric|min:1|max:100000000',
            'description' => 'required|string|max:255',
            'merchant' => 'nullable|string|max:120',
            'project' => 'nullable|string|max:120',
            'project_id' => ['nullable', 'uuid', Rule::exists('prj_projects', 'id')->where('company_id', $company)->whereNull('deleted_at')],
            'status' => 'nullable|in:draft,submitted,approved,rejected',
            'decision_note' => 'nullable|string|max:255',
            'analytic' => 'nullable|array',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('notes_frais.approve|notes_frais.manage')) {
            $q->whereIn('employee_id', Employee::where('user_id', $user->id)->select('id'));
        }
    }

    public function getHasReceiptAttribute(): bool
    {
        return (bool) $this->receipt_path;
    }

    /** Nom du salarié : le responsable qui valide n'a pas forcément accès aux fiches RH. */
    public function getEmployeeNameAttribute(): ?string
    {
        return Employee::withTrashed()->find($this->employee_id)?->full_name;
    }

    public function getOverLimitAttribute(): bool
    {
        $max = $this->category_id ? ExpenseCategory::find($this->category_id)?->max_amount : null;

        return $max !== null && $this->amount > $max;
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        if (array_key_exists('analytic', $data)) {
            $data['analytic'] = \App\Modules\Core\Models\TradeDocument::cleanAnalytic($data['analytic']);
        }
        $user = auth()->user();
        $approver = ! $user || static::$workflowDecision || $user->hasPermission('notes_frais.approve|notes_frais.manage');
        $own = $user ? Employee::where('user_id', $user->id)->value('id') : null;
        $previous = $creating ? null : $this->status;
        $status = $data['status'] ?? ($creating ? 'draft' : $this->status);
        $employee = $data['employee_id'] ?? $this->employee_id;
        // Une dépense existante doit déjà être la sienne : on ne s'approprie pas celle d'un collègue en changeant employee_id
        $mine = $own && $employee === $own && ($creating || $this->employee_id === $own);

        if (! $creating && $previous === 'paid') {
            throw new \DomainException('Cette dépense est déjà remboursée.');
        }
        if (! $approver && ! $mine) {
            throw new \DomainException('Vous ne pouvez saisir que vos propres dépenses.');
        }
        if ($creating && ! $approver && ! $user->hasPermission('notes_frais.self')) {
            throw new \DomainException('Saisie des notes de frais non autorisée.');
        }

        if (! $creating && ! $approver && in_array($previous, ['submitted', 'approved'], true)) {
            unset($data['analytic']); // la ventilation d'une dépense envoyée revient au responsable
        }

        $editing = (bool) array_intersect(array_keys($data), self::CONTENT)
            && collect(self::CONTENT)->contains(fn ($k) => array_key_exists($k, $data) && (string) $data[$k] !== (string) ($k === 'date' ? $this->date?->toDateString() : $this->{$k}) && ! ($k === 'amount' && abs((float) $data[$k] - (float) $this->amount) < 0.001));
        if (! $creating && $editing && in_array($previous, ['approved', 'submitted'], true) && ! ($mine && $previous === 'submitted' && $status === 'draft')) {
            if ($previous === 'approved' || ! $approver) {
                throw new \DomainException($previous === 'approved' ? 'Dépense approuvée : elle ne se modifie plus.' : 'Dépense envoyée : reprenez-la en brouillon pour la modifier.');
            }
        }

        if ($status !== $previous) {
            $decision = in_array($status, ['approved', 'rejected'], true) || ($previous === 'approved' && $status === 'submitted');
            if ($decision && ! static::$workflowDecision && class_exists(\App\Modules\Approbations\ApprovalService::class)
                && ($wf = app(\App\Modules\Approbations\ApprovalService::class)->expensePending($this->id))) {
                throw new \DomainException('Cette note de frais suit le circuit d\'approbation « '.$wf->rule_name.' » : décidez depuis l\'écran « Approbations ».');
            }
            if ($decision) {
                if (! $approver) {
                    throw new \DomainException('Seul un responsable valide ou refuse une note de frais.');
                }
                if ($mine && ! $user?->is_super_admin && ! static::$workflowDecision) {
                    throw new \DomainException('Vous ne pouvez pas valider vos propres dépenses.');
                }
                if (! in_array($previous, ['submitted', 'approved'], true)) {
                    throw new \DomainException('La dépense doit d\'abord être envoyée par le salarié.');
                }
                if ($status === 'rejected' && blank($data['decision_note'] ?? null)) {
                    throw new \DomainException('Indiquez le motif du refus.');
                }
                $this->decided_by = $user?->id;
                $this->decided_at = now();
            } elseif ($status === 'submitted') {
                if (! in_array($previous, [null, 'draft', 'rejected'], true)) {
                    throw new \DomainException('Cette dépense a déjà été envoyée.');
                }
                $category = ExpenseCategory::find($data['category_id'] ?? $this->category_id);
                $min = (float) (app(NotesFraisService::class)->settings(app('tenant'))['receipt_from'] ?? 0);
                $amount = (float) ($data['amount'] ?? $this->amount);
                if (! $this->receipt_path && ($category?->receipt_required ?? true) && $amount >= $min) {
                    throw new \DomainException('Joignez la photo du justificatif avant d\'envoyer.');
                }
                $this->submitted_by = $user?->id;
                $this->submitted_at = now();
                $this->decision_note = null;
                unset($data['decision_note']);
            } elseif ($status === 'draft' && ! in_array($previous, [null, 'submitted', 'rejected'], true)) {
                throw new \DomainException('Cette dépense ne peut plus revenir en brouillon.');
            }
        } elseif (! $approver) {
            unset($data['decision_note']);
        }
        if (! $creating && $previous === 'rejected' && $editing && $status === 'rejected') {
            $status = 'draft'; // corrigée après refus : repasse en brouillon
        }
        $data['status'] = $status;
    }

    /** Décision prise au terme d'un circuit d'approbation (droits de l'approbateur final). */
    public static bool $workflowDecision = false;

    public function afterEntityWrite(array $data, bool $creating): void
    {
        if (! $creating && ! $this->wasChanged('status')) {
            return;
        }
        if (class_exists(\App\Modules\Approbations\ApprovalService::class)) {
            $wf = app(\App\Modules\Approbations\ApprovalService::class);
            if ($this->status === 'submitted' && ! static::$workflowDecision) {
                $wf->expenseSubmitted($this);
            } elseif ($this->status === 'draft' && ! static::$workflowDecision) {
                $wf->expenseDraft($this->id);
            }
        }
        $svc = app(NotesFraisService::class);
        $emp = Employee::withTrashed()->find($this->employee_id);
        $amount = number_format($this->amount, 0, ',', ' ');
        $user = auth()->user();
        ActivityLog::record('notes_frais.'.$this->status, $this, ['amount' => $this->amount]);

        if ($this->status === 'approved') {
            $svc->postApproval($this);
            if ($this->project_id && app('tenant')->hasModule('projets')) {
                rescue(fn () => ($p = \App\Modules\Projets\Models\Project::find($this->project_id)) && app(\App\Modules\Projets\ProjectCostService::class)->checkAlert($p), null, false);
            }
        } elseif (($this->getPrevious()['status'] ?? null) === 'approved') {
            $svc->unpostApproval($this);
        }

        if ($this->status === 'submitted') {
            $approvers = User::where('company_id', $this->company_id)->where('is_active', true)->where('id', '!=', $user?->id ?? 0)->get()
                ->filter(fn ($u) => $u->hasPermission('notes_frais.approve'))->pluck('id')->all();
            AppNotification::send($this->company_id, $approvers, 'notes_frais', 'Note de frais à valider', ($emp?->full_name ?? '').' : '.$this->description.' — '.$amount, 'notes_frais', $this->id);
        } elseif (in_array($this->status, ['approved', 'rejected'], true) && $emp?->user_id && $emp->user_id !== $user?->id) {
            AppNotification::send($this->company_id, [$emp->user_id], 'notes_frais', 'Note de frais '.mb_strtolower(self::STATUSES[$this->status]),
                $this->description.' — '.$amount.($this->decision_note ? ' — '.$this->decision_note : ''), 'notes_frais', $this->id);
        }
    }

    public function beforeEntityDelete(): void
    {
        $user = auth()->user();
        if (in_array($this->status, ['approved', 'paid'], true)) {
            throw new \DomainException('Une dépense approuvée ou remboursée ne se supprime pas.');
        }
        if ($user && ! $user->hasPermission('notes_frais.manage') && ! in_array($this->status, ['draft', 'rejected'], true)) {
            throw new \DomainException('Reprenez la dépense en brouillon avant de la supprimer.');
        }
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category_id')->withTrashed();
    }
}
