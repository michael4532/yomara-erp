<?php

namespace App\Modules\Approbations\Models;

use App\Models\Concerns\Syncable;
use App\Models\User;
use App\Modules\Approbations\ApprovalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Demande d'approbation (lecture seule côté application : les décisions passent par l'API). */
class ApprovalRequest extends Model
{
    use Syncable;

    protected $table = 'wf_requests';

    public static string $syncEntity = 'wf_requests';

    public static string $viewPermission = ApprovalService::READERS;

    public static string $managePermission = 'approbations.manage';

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['company_id', 'deleted_at'];

    protected function casts(): array
    {
        return ['steps' => 'array', 'amount' => 'float', 'discount' => 'float', 'auto_validate' => 'boolean', 'current_step' => 'integer', 'reminder_hours' => 'integer',
            'requested_by' => 'integer', 'step_started_at' => 'datetime', 'last_reminded_at' => 'datetime', 'escalated_at' => 'datetime', 'decided_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        return [];
    }

    public static function syncWritable(): array
    {
        return [];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        throw new \DomainException('Les demandes d\'approbation se décident depuis l\'écran « Approbations ».');
    }

    public function beforeEntityDelete(): void
    {
        throw new \DomainException('Une demande d\'approbation ne se supprime pas : annulez-la.');
    }

    public function decisions()
    {
        return $this->hasMany(Decision::class, 'request_id')->orderBy('id');
    }

    /** Le demandeur, les approbateurs (toutes étapes) et les responsables. */
    public static function syncVisibility(Builder $q, $user): void
    {
        if ($user->hasPermission('approbations.view|approbations.manage')) {
            return;
        }
        $svc = app(ApprovalService::class);
        $ids = static::query()->get()->filter(fn ($r) => (int) $r->requested_by === (int) $user->id || $svc->involves($r, $user))->pluck('id')->all();
        $q->whereIn('id', $ids ?: ['00000000-0000-0000-0000-000000000000']);
    }

    public function toSyncArray(): array
    {
        $svc = app(ApprovalService::class);
        $names = User::whereIn('id', $this->decisions->pluck('user_id')->merge($this->decisions->pluck('on_behalf_of'))->push($this->requested_by)->filter()->unique())->pluck('name', 'id');

        return $this->attributesToArray() + [
            'target_label' => ApprovalService::TARGETS[$this->target] ?? $this->target,
            'requester_name' => $names[$this->requested_by] ?? null,
            'current_approver_ids' => $this->status === 'pending' ? $svc->actors($this) : [],
            'current_step_name' => $this->steps[$this->current_step]['name'] ?? null,
            'decisions' => $this->decisions->map(fn ($d) => ['step' => $d->step, 'user' => $names[$d->user_id] ?? null, 'on_behalf_of' => $d->on_behalf_of ? ($names[$d->on_behalf_of] ?? null) : null,
                'decision' => $d->decision, 'comment' => $d->comment, 'at' => $d->created_at?->toIso8601String()])->values(),
        ];
    }
}
