<?php

namespace App\Modules\Talents\Models;

use App\Models\AppNotification;
use App\Models\Concerns\Syncable;
use App\Modules\Rh\Models\Department;
use App\Modules\Rh\Models\Employee;
use App\Modules\Rh\RequestNotifier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/**
 * Entretien d'évaluation : annuel, fin de période d'essai, mi-parcours ou de départ.
 * Circuit : planifié → auto-évaluation du salarié → évaluation du responsable → terminé.
 * Le salarié ne remplit que sa partie ; le responsable (évaluateur) la sienne ; la RH tout.
 */
class Review extends Model
{
    use Syncable;

    protected $table = 'tal_reviews';

    public static string $syncEntity = 'tal_reviews';

    public static string $viewPermission = 'talents.view|talents.manage|rh.self';

    public static string $managePermission = 'talents.manage|rh.self';

    public const TYPES = ['annual' => 'Entretien annuel', 'trial' => 'Fin de période d\'essai', 'mid' => 'Point à mi-parcours', 'exit' => 'Entretien de départ'];

    public const STATUSES = ['planned' => 'Planifié', 'self' => 'Auto-évaluation', 'manager' => 'Chez le responsable', 'done' => 'Terminé', 'cancelled' => 'Annulé'];

    public const OUTCOMES = [
        'confirm' => 'Confirmation dans le poste', 'extend' => 'Renouvellement de la période d\'essai', 'end' => 'Fin de la période d\'essai',
        'promotion' => 'Promotion proposée', 'raise' => 'Augmentation proposée', 'training' => 'Formation à prévoir', 'none' => 'Pas de changement',
    ];

    public const CRITERIA = ['Qualité du travail', 'Productivité', 'Autonomie', 'Esprit d\'équipe', 'Ponctualité et assiduité', 'Communication', 'Sens des responsabilités'];

    /** Champs que remplit le salarié lui-même. */
    protected const EMPLOYEE_FIELDS = ['employee_comment', 'ratings', 'status', 'training_needs'];

    /** Champs que remplit le responsable. */
    protected const REVIEWER_FIELDS = ['objectives', 'ratings', 'strengths', 'improvements', 'manager_comment', 'next_objectives', 'training_needs', 'outcome', 'status', 'scheduled_on'];

    protected $fillable = ['employee_id', 'reviewer_id', 'type', 'period', 'scheduled_on', 'status', 'objectives', 'ratings', 'outcome', 'strengths', 'improvements',
        'employee_comment', 'manager_comment', 'next_objectives', 'training_needs'];

    protected function casts(): array
    {
        return ['scheduled_on' => 'date', 'completed_at' => 'datetime', 'objectives' => 'array', 'ratings' => 'array', 'next_objectives' => 'array', 'score' => 'float', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'employee_id' => ['required', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'reviewer_id' => ['nullable', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'type' => ['nullable', Rule::in(array_keys(self::TYPES))],
            'period' => 'nullable|string|max:40',
            'scheduled_on' => 'nullable|date',
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'objectives' => 'nullable|array|max:30',
            'objectives.*.title' => 'required|string|max:300',
            'objectives.*.weight' => 'nullable|numeric|min:0|max:100',
            'objectives.*.result' => 'nullable|string|max:1000',
            'objectives.*.score' => 'nullable|numeric|min:0|max:5',
            'ratings' => 'nullable|array|max:30',
            'ratings.*.criterion' => 'required|string|max:120',
            'ratings.*.self' => 'nullable|numeric|min:0|max:5',
            'ratings.*.score' => 'nullable|numeric|min:0|max:5',
            'outcome' => ['nullable', Rule::in(array_keys(self::OUTCOMES))],
            'strengths' => 'nullable|string|max:5000',
            'improvements' => 'nullable|string|max:5000',
            'employee_comment' => 'nullable|string|max:5000',
            'manager_comment' => 'nullable|string|max:5000',
            'next_objectives' => 'nullable|array|max:30',
            'next_objectives.*.title' => 'required|string|max:300',
            'next_objectives.*.due' => 'nullable|date',
            'training_needs' => 'nullable|string|max:3000',
        ];
    }

    /** Fiche salarié de l'utilisateur connecté. */
    public static function meId(?\App\Models\User $user): ?string
    {
        return $user ? Employee::where('user_id', $user->id)->value('id') : null;
    }

    /** Salariés des départements dirigés par cet employé (sous-départements compris). */
    public static function teamOf(?string $employeeId)
    {
        if (! $employeeId) {
            return collect();
        }
        $depts = Department::ledBy($employeeId)->pluck('id');

        return Employee::whereIn('department_id', $depts)->orWhere('manager_id', $employeeId)->pluck('id');
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('talents.view|talents.manage')) {
            $me = self::meId($user);
            $team = self::teamOf($me)->all();
            $q->where(fn ($w) => $w->where('employee_id', $me ?? '-')->orWhere('reviewer_id', $me ?? '-')->orWhereIn('employee_id', $team ?: ['-']));
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $hr = ! $user || $user->hasPermission('talents.manage');
        if (! $hr) {
            $me = self::meId($user);
            $employee = $data['employee_id'] ?? $this->employee_id;
            $isReviewer = $me && (($this->reviewer_id ?? null) === $me || (! $this->reviewer_id && self::teamOf($me)->contains($employee)));
            if ($creating) {
                // Un responsable peut planifier l'entretien d'un membre de son équipe
                if (! $me || ! self::teamOf($me)->contains($employee)) {
                    throw new \DomainException('Seuls la RH et le responsable du salarié organisent ses entretiens.');
                }
                $data['reviewer_id'] = $me;
            } elseif ($isReviewer) {
                $data = array_intersect_key($data, array_flip(self::REVIEWER_FIELDS));
            } elseif ($me && $this->employee_id === $me) {
                if ($this->status !== 'self' && $this->status !== 'planned') {
                    throw new \DomainException('Votre partie de l\'entretien est déjà transmise à votre responsable.');
                }
                $data = array_intersect_key($data, array_flip(self::EMPLOYEE_FIELDS));
                // Le salarié ne note que sa colonne « auto-évaluation »
                if (isset($data['ratings'])) {
                    $prev = collect((array) $this->ratings)->keyBy('criterion');
                    $data['ratings'] = array_map(fn ($r) => ['criterion' => $r['criterion'], 'self' => $r['self'] ?? null, 'score' => $prev[$r['criterion']]['score'] ?? null], $data['ratings']);
                }
                if (isset($data['status']) && ! in_array($data['status'], ['self', 'manager'], true)) {
                    unset($data['status']);
                }
            } else {
                throw new \DomainException('Cet entretien ne vous concerne pas.');
            }
        }
        if ($creating) {
            $data['type'] ??= 'annual';
            $data['status'] ??= 'planned';
            $data['period'] ??= $data['type'] === 'annual' ? (string) now()->year : null;
            if (empty($data['ratings'])) {
                $data['ratings'] = array_map(fn ($c) => ['criterion' => $c, 'self' => null, 'score' => null], self::CRITERIA);
            }
            if (empty($data['reviewer_id']) && ($e = Employee::find($data['employee_id']))) {
                $data['reviewer_id'] = RequestNotifier::managerOf($e)?->id;
            }
        }
        if (($data['employee_id'] ?? $this->employee_id) === ($data['reviewer_id'] ?? $this->reviewer_id) && ($data['reviewer_id'] ?? $this->reviewer_id)) {
            throw new \DomainException('Le salarié ne peut pas être son propre évaluateur.');
        }
        // Note globale : moyenne des compétences notées par le responsable et des objectifs pondérés
        $ratings = collect($data['ratings'] ?? $this->ratings ?? [])->pluck('score')->filter(fn ($v) => $v !== null && $v !== '');
        $objectives = collect($data['objectives'] ?? $this->objectives ?? [])->filter(fn ($o) => isset($o['score']) && $o['score'] !== null);
        $parts = [];
        if ($ratings->isNotEmpty()) {
            $parts[] = $ratings->avg();
        }
        if ($objectives->isNotEmpty()) {
            $w = $objectives->sum(fn ($o) => (float) ($o['weight'] ?? 1) ?: 1);
            $parts[] = $objectives->sum(fn ($o) => (float) $o['score'] * ((float) ($o['weight'] ?? 1) ?: 1)) / max(1e-9, $w);
        }
        $this->score = $parts ? round(array_sum($parts) / count($parts), 2) : null;
        $status = $data['status'] ?? $this->status;
        if ($status === 'done' && ! $this->completed_at) {
            $this->completed_at = now();
        }
    }

    public function afterEntityWrite(array $data, bool $created): void
    {
        $company = app('tenant');
        $emp = Employee::find($this->employee_id);
        $reviewer = $this->reviewer_id ? Employee::find($this->reviewer_id) : null;
        $label = (self::TYPES[$this->type] ?? 'Entretien').' — '.($emp?->full_name ?? '');
        $actor = auth()->id();
        $notify = function (?Employee $who, string $title, string $body) use ($company, $actor) {
            if ($who?->user_id && $who->user_id !== $actor) {
                AppNotification::send($company->id, [$who->user_id], 'talents.review', $title, $body, 'talents', $this->id, $actor);
            }
        };
        if ($created) {
            $notify($emp, 'Entretien planifié', $label.($this->scheduled_on ? ' le '.$this->scheduled_on->format('d/m/Y') : '').' : préparez votre auto-évaluation.');
            $notify($reviewer, 'Entretien à mener', $label);
        } elseif ($this->wasChanged('status')) {
            match ($this->status) {
                'manager' => $notify($reviewer, 'Auto-évaluation reçue', $label),
                'done' => $notify($emp, 'Entretien terminé', $label.($this->score ? ' · note '.number_format($this->score, 1, ',', ' ').'/5' : '')),
                default => null,
            };
        }
    }

    public function toSyncArray(): array
    {
        $a = $this->attributesToArray();
        // Le salarié ne voit pas l'évaluation du responsable tant que l'entretien n'est pas terminé
        $user = auth()->user();
        if ($user && ! $user->hasPermission('talents.view|talents.manage') && $this->status !== 'done' && self::meId($user) === $this->employee_id) {
            foreach (['strengths', 'improvements', 'manager_comment', 'outcome', 'score'] as $k) {
                $a[$k] = null;
            }
            $a['ratings'] = array_map(fn ($r) => ['criterion' => $r['criterion'], 'self' => $r['self'] ?? null, 'score' => null], (array) $this->ratings);
        }

        return $a;
    }
}
