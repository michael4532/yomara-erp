<?php

namespace App\Modules\Achats\Models;

use App\Models\Concerns\Syncable;
use App\Models\Sequence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

/** Demande d'achat interne (un service exprime un besoin, un responsable l'approuve). */
class PurchaseRequest extends Model
{
    use Syncable;

    public static string $syncEntity = 'purchase_requests';

    public static string $viewPermission = 'achats.view|achats.request';

    public static string $managePermission = 'achats.request|achats.manage|achats.approve';

    public static array $searchable = ['number', 'title', 'department'];

    public const STATUSES = ['draft' => 'Brouillon', 'submitted' => 'À approuver', 'approved' => 'Approuvée', 'rejected' => 'Refusée', 'ordered' => 'Commandée', 'cancelled' => 'Annulée'];

    protected $fillable = ['department', 'title', 'reason', 'needed_by', 'priority', 'lines', 'status', 'rejection_reason'];

    protected function casts(): array
    {
        return ['needed_by' => 'date:Y-m-d', 'lines' => 'array', 'order_ids' => 'array', 'estimated_total' => 'float', 'priority' => 'integer',
            'requester_id' => 'integer', 'approver_id' => 'integer', 'approved_at' => 'datetime', 'version' => 'integer'];
    }

    public static function syncRules(): array
    {
        $company = app('tenant')->id;

        return [
            'department' => 'nullable|string|max:120',
            'title' => 'required|string|max:255',
            'reason' => 'nullable|string|max:3000',
            'needed_by' => 'nullable|date',
            'priority' => 'nullable|integer|between:0,3',
            'lines' => 'required|array|min:1|max:100',
            'lines.*.product_id' => ['nullable', 'uuid', Rule::exists('products', 'id')->where('company_id', $company)],
            'lines.*.description' => 'required|string|max:255',
            'lines.*.quantity' => 'required|numeric|min:0.001',
            'lines.*.unit' => 'nullable|string|max:30',
            'lines.*.estimated_price' => 'nullable|numeric|min:0',
            'lines.*.supplier_id' => ['nullable', 'uuid', Rule::exists('partners', 'id')->where('company_id', $company)],
            'status' => 'nullable|in:draft,submitted,approved,rejected,cancelled',
            'rejection_reason' => 'nullable|string|max:255',
        ];
    }

    public function beforeEntityWrite(array &$data, bool $creating): void
    {
        $user = auth()->user();
        $to = $data['status'] ?? null;
        $from = $creating ? 'draft' : $this->status;
        if (! $creating && in_array($this->status, ['ordered', 'cancelled', 'rejected'], true) && $to !== 'draft') {
            throw new \DomainException('Cette demande est close.');
        }
        if (! $creating && in_array($this->status, ['submitted', 'approved'], true) && array_intersect(array_keys($data), ['lines', 'title']) && ! ($to === 'draft')) {
            if (! $user || ! $user->hasPermission('achats.manage')) {
                throw new \DomainException('La demande est envoyée : repassez-la en brouillon pour la modifier.');
            }
        }
        if ($user && ! $creating && $this->requester_id !== $user->id && ! $user->hasPermission('achats.manage') && ! $user->hasPermission('achats.approve')) {
            throw new \DomainException('Cette demande n\'est pas la vôtre.');
        }
        if ($to && $to !== $from) {
            $allowed = ['draft' => ['submitted', 'cancelled'], 'submitted' => ['approved', 'rejected', 'draft', 'cancelled'], 'approved' => ['draft', 'cancelled'], 'rejected' => ['draft']][$from] ?? [];
            if (! in_array($to, $allowed, true)) {
                throw new \DomainException('Changement de statut impossible.');
            }
            if (in_array($to, ['approved', 'rejected'], true) && $user && ! $this->canApprove($user, $data)) {
                throw new \DomainException('Vous n\'avez pas le droit d\'approuver cette demande.');
            }
            if ($to === 'rejected' && empty($data['rejection_reason']) && empty($this->rejection_reason)) {
                throw new \DomainException('Indiquez le motif du refus.');
            }
        }
    }

    public function toSyncArray(): array
    {
        $names = \App\Models\User::whereIn('id', array_filter([$this->requester_id, $this->approver_id]))->pluck('name', 'id');

        return $this->toArray() + ['requester_name' => $names[$this->requester_id] ?? null, 'approver_name' => $names[$this->approver_id] ?? null];
    }

    /** Responsable des achats jusqu'au seuil, approbateur au-delà. */
    public function canApprove($user, array $data = []): bool
    {
        if ($user->hasPermission('achats.approve')) {
            return true;
        }
        $limit = (float) (app('tenant')->setting('purchase_approval_limit') ?? 0);
        $total = self::totalOf($data['lines'] ?? $this->lines ?? []);

        return $user->hasPermission('achats.manage') && ($limit <= 0 || $total <= $limit);
    }

    public static function totalOf(array $lines): float
    {
        return round(collect($lines)->sum(fn ($l) => (float) ($l['quantity'] ?? 0) * (float) ($l['estimated_price'] ?? 0)), 2);
    }

    protected static function booted(): void
    {
        static::creating(function (self $r) {
            $r->number ??= Sequence::next($r->company_id, 'purchase_request', 'DA');
            $r->requester_id ??= auth()->id();
        });
        static::saving(function (self $r) {
            $r->estimated_total = self::totalOf($r->lines ?? []);
            if ($r->isDirty('status')) {
                if (in_array($r->status, ['approved', 'rejected'], true)) {
                    $r->approver_id = auth()->id() ?? $r->approver_id;
                    $r->approved_at = now();
                }
                if ($r->status === 'draft') {
                    $r->approver_id = null;
                    $r->approved_at = null;
                    $r->rejection_reason = null;
                }
            }
        });
    }
}
