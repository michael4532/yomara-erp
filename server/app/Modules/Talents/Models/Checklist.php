<?php

namespace App\Modules\Talents\Models;

use App\Models\Concerns\Syncable;
use App\Modules\Rh\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Parcours d'arrivée (intégration) ou de départ d'un salarié : liste de tâches à cocher par la RH, le responsable, l'informatique et le salarié. */
class Checklist extends Model
{
    use Syncable;

    protected $table = 'tal_checklists';

    public static string $syncEntity = 'tal_checklists';

    public static string $viewPermission = 'talents.view|talents.manage|rh.self';

    public static string $managePermission = 'talents.manage|rh.self';

    public const KINDS = ['onboarding' => 'Arrivée', 'offboarding' => 'Départ'];

    public const OWNERS = ['rh' => 'RH', 'manager' => 'Responsable', 'it' => 'Informatique', 'employee' => 'Salarié'];

    public const TEMPLATES = [
        'onboarding' => [
            ['Contrat de travail signé', 'rh'], ['Déclaration à la sécurité sociale (CNPS…)', 'rh'], ['Dossier administratif complet (pièces, RIB, photo)', 'employee'],
            ['Fiche de poste remise et expliquée', 'manager'], ['Poste de travail et matériel remis', 'it'], ['Comptes et accès créés (messagerie, logiciel)', 'it'],
            ['Badge / pointage configuré', 'rh'], ['Présentation à l\'équipe et visite des locaux', 'manager'], ['Règlement intérieur et consignes de sécurité', 'rh'],
            ['Objectifs des 3 premiers mois fixés', 'manager'], ['Point de fin de période d\'essai planifié', 'rh'],
        ],
        'offboarding' => [
            ['Lettre de démission / de licenciement archivée', 'rh'], ['Passation des dossiers', 'manager'], ['Matériel restitué (ordinateur, téléphone, badge, clés)', 'employee'],
            ['Comptes et accès désactivés', 'it'], ['Solde de tout compte calculé et payé', 'rh'], ['Certificat de travail remis', 'rh'],
            ['Attestation sécurité sociale / sortie déclarée', 'rh'], ['Entretien de départ réalisé', 'manager'],
        ],
    ];

    protected $fillable = ['employee_id', 'kind', 'title', 'start_date', 'due_date', 'items', 'notes'];

    protected function casts(): array
    {
        return ['start_date' => 'date', 'due_date' => 'date', 'items' => 'array', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'employee_id' => ['required', 'uuid', Rule::exists('rh_employees', 'id')->where('company_id', $company)],
            'kind' => ['nullable', Rule::in(array_keys(self::KINDS))],
            'title' => 'nullable|string|max:160',
            'start_date' => 'nullable|date',
            'due_date' => 'nullable|date',
            'items' => 'nullable|array|max:100',
            'items.*.id' => 'nullable|string|max:40',
            'items.*.label' => 'required|string|max:300',
            'items.*.owner' => ['nullable', Rule::in(array_keys(self::OWNERS))],
            'items.*.done' => 'nullable|boolean',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    public static function syncVisibility(Builder $q, $user): void
    {
        if (! $user->hasPermission('talents.view|talents.manage')) {
            $me = Review::meId($user);
            $team = Review::teamOf($me)->all();
            $q->where(fn ($w) => $w->where('employee_id', $me ?? '-')->orWhereIn('employee_id', $team ?: ['-']));
        }
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $hr = ! $user || $user->hasPermission('talents.manage');
        $prev = collect((array) $this->items)->keyBy('id');
        if (! $hr) {
            $me = Review::meId($user);
            $isManager = $me && Review::teamOf($me)->contains($this->employee_id);
            if ($creating || (! $isManager && $this->employee_id !== $me)) {
                throw new \DomainException('Seule la RH organise les parcours d\'arrivée et de départ.');
            }
            // Le salarié coche ses tâches, le responsable les siennes et celles du salarié
            $allowed = $isManager ? ['manager', 'employee'] : ['employee'];
            $items = [];
            foreach ((array) ($data['items'] ?? []) as $it) {
                $old = $prev[$it['id'] ?? ''] ?? null;
                if (! $old) {
                    continue;
                }
                if (in_array($old['owner'] ?? 'rh', $allowed, true)) {
                    $old['done'] = (bool) ($it['done'] ?? false);
                }
                $items[] = $old;
            }
            $data = ['items' => $items ?: array_values($prev->all())];
        }
        if ($creating) {
            $data['kind'] ??= 'onboarding';
            if (empty($data['items'])) {
                $data['items'] = array_map(fn ($t) => ['label' => $t[0], 'owner' => $t[1], 'done' => false], self::TEMPLATES[$data['kind']]);
            }
            $emp = Employee::find($data['employee_id']);
            $data['title'] ??= self::KINDS[$data['kind']].' de '.($emp?->full_name ?? '');
            $data['start_date'] ??= $data['kind'] === 'onboarding' ? ($emp?->hire_date?->toDateString() ?? now()->toDateString()) : now()->toDateString();
            if (Checklist::where('employee_id', $data['employee_id'])->where('kind', $data['kind'])->where('status', 'open')->exists()) {
                throw new \DomainException('Un parcours '.mb_strtolower(self::KINDS[$data['kind']]).' est déjà en cours pour ce salarié.');
            }
        }
        if (isset($data['items'])) {
            $data['items'] = array_values(array_map(function ($it) use ($prev, $user) {
                $it['id'] = ($it['id'] ?? null) ?: Str::random(8);
                $it['owner'] ??= 'rh';
                $it['done'] = (bool) ($it['done'] ?? false);
                $old = $prev[$it['id']] ?? null;
                if ($it['done'] && ! ($old['done'] ?? false)) {
                    $it['done_at'] = now()->toIso8601String();
                    $it['done_by'] = $user?->name;
                } elseif (! $it['done']) {
                    unset($it['done_at'], $it['done_by']);
                } else {
                    $it['done_at'] = $old['done_at'] ?? null;
                    $it['done_by'] = $old['done_by'] ?? null;
                }

                return $it;
            }, $data['items']));
            $this->status = collect($data['items'])->every(fn ($i) => $i['done']) ? 'done' : 'open';
        }
    }

    public function toSyncArray(): array
    {
        $a = $this->attributesToArray();
        $items = (array) $this->items;
        $a['progress'] = $items ? round(100 * count(array_filter($items, fn ($i) => ! empty($i['done']))) / count($items)) : 0;

        return $a;
    }

    /** Suppression (y compris par la synchronisation hors ligne) réservée aux responsables. */
    public function beforeEntityDelete(): void
    {
        $user = auth()->user();
        if ($user && ! $user->hasPermission('talents.manage')) {
            throw new \DomainException('Seuls les responsables RH peuvent supprimer une liste d\'intégration.');
        }
    }
}
