<?php

namespace App\Modules\Approbations;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Modules\Approbations\Models\ApprovalRequest;
use App\Modules\Approbations\Models\Delegation;
use Illuminate\Http\Request;

class ApprobationsController extends Controller
{
    public function __construct(private ApprovalService $svc) {}

    private function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /** Cibles, rôles et personnes (formulaires des circuits et des délégations). */
    public function meta()
    {
        return ['data' => [
            'targets' => collect(ApprovalService::TARGETS)->map(fn ($l, $k) => ['key' => $k, 'label' => $l])->values(),
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'users' => User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'role_id']),
            'currency' => app('tenant')->currency,
        ]];
    }

    public function inbox(Request $r)
    {
        return ['data' => $this->svc->inbox($r->user())->map->toSyncArray()->values()];
    }

    public function index(Request $r)
    {
        $v = $r->validate(['status' => 'nullable|in:pending,approved,rejected,cancelled', 'mine' => 'nullable|boolean', 'record_id' => 'nullable|uuid']);
        $q = ApprovalRequest::query()->latest()->limit(300);
        if (! empty($v['status'])) {
            $q->where('status', $v['status']);
        }
        if (! empty($v['record_id'])) {
            $q->where('record_id', $v['record_id']);
        }
        $user = $r->user();
        $all = $user->hasPermission('approbations.view|approbations.manage') && empty($v['mine']);
        $rows = $q->get()->filter(fn ($x) => $all || (int) $x->requested_by === (int) $user->id || (empty($v['mine']) && $this->svc->involves($x, $user)));

        return ['data' => $rows->map->toSyncArray()->values()];
    }

    public function show(Request $r, string $id)
    {
        $req = ApprovalRequest::findOrFail($id);
        $user = $r->user();
        if (! $user->hasPermission('approbations.view|approbations.manage') && (int) $req->requested_by !== (int) $user->id && ! $this->svc->involves($req, $user)) {
            return response()->json(['message' => 'Demande non accessible.'], 403);
        }

        return ['data' => $req->toSyncArray()];
    }

    public function decide(Request $r, string $id)
    {
        $v = $r->validate(['decision' => 'required|in:approved,rejected', 'comment' => 'nullable|string|max:1000']);

        return $this->guard(fn () => ['data' => $this->svc->decide(ApprovalRequest::findOrFail($id), $r->user(), $v['decision'], $v['comment'] ?? null)->fresh()->toSyncArray()]);
    }

    public function cancel(Request $r, string $id)
    {
        return $this->guard(function () use ($r, $id) {
            $req = ApprovalRequest::findOrFail($id);
            $this->svc->cancel($req, $r->user());

            return ['data' => $req->fresh()->toSyncArray()];
        });
    }

    // ------------------------------------------------------------ délégations

    private function delegationArray(Delegation $d): array
    {
        $names = User::whereIn('id', [$d->user_id, $d->delegate_id])->pluck('name', 'id');

        return $d->toArray() + ['user_name' => $names[$d->user_id] ?? null, 'delegate_name' => $names[$d->delegate_id] ?? null,
            'active' => $d->starts_on->lte(today()) && $d->ends_on->gte(today())];
    }

    public function delegations(Request $r)
    {
        $user = $r->user();
        $q = Delegation::query()->orderByDesc('starts_on');
        if (! $user->hasPermission('approbations.manage')) {
            $q->where(fn ($w) => $w->where('user_id', $user->id)->orWhere('delegate_id', $user->id));
        }

        return ['data' => $q->get()->map(fn ($d) => $this->delegationArray($d))];
    }

    public function storeDelegation(Request $r)
    {
        $v = $r->validate(['user_id' => 'nullable|integer', 'delegate_id' => 'required|integer', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after_or_equal:starts_on', 'note' => 'nullable|string|max:255']);
        $user = $r->user();
        $from = (int) ($v['user_id'] ?? $user->id);
        if ($from !== (int) $user->id && ! $user->hasPermission('approbations.manage')) {
            return response()->json(['message' => 'Vous ne pouvez déléguer que vos propres approbations.'], 403);
        }
        $ids = User::where('company_id', app('tenant')->id)->whereIn('id', [$from, $v['delegate_id']])->pluck('id')->all();
        if (count($ids) !== 2 || $from === (int) $v['delegate_id']) {
            return response()->json(['message' => 'Choisissez une autre personne de la société.'], 422);
        }
        $d = Delegation::create(['user_id' => $from, 'delegate_id' => $v['delegate_id'], 'starts_on' => $v['starts_on'], 'ends_on' => $v['ends_on'], 'note' => $v['note'] ?? null]);
        \App\Models\AppNotification::send(app('tenant')->id, [$v['delegate_id']], 'approval', 'Délégation d\'approbations',
            User::find($from)?->name.' vous confie ses approbations du '.$d->starts_on->format('d/m/Y').' au '.$d->ends_on->format('d/m/Y').'.', 'approbations');

        return response()->json(['data' => $this->delegationArray($d)], 201);
    }

    public function destroyDelegation(Request $r, int $id)
    {
        $d = Delegation::findOrFail($id);
        if ((int) $d->user_id !== (int) $r->user()->id && ! $r->user()->hasPermission('approbations.manage')) {
            return response()->json(['message' => 'Délégation non modifiable.'], 403);
        }
        $d->delete();

        return response()->noContent();
    }
}
