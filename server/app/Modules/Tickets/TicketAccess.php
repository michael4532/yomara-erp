<?php

namespace App\Modules\Tickets;

use App\Models\User;
use App\Modules\Tickets\Models\Department;
use App\Modules\Tickets\Models\Member;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qui voit quoi :
 *  - chacun voit ses demandes et les tickets qui lui sont attribués ;
 *  - un collaborateur voit la file de son service ;
 *  - un chef (ou adjoint) voit tout son service : demandes reçues ET demandes envoyées par ses équipes ;
 *  - un responsable hiérarchique (DGA, RH… ou chef d'un service parent) voit tous les services placés sous lui ;
 *  - la direction (permission tickets.view_all, ex. DG) voit tout.
 */
class TicketAccess
{
    /** @var array<int, string> department_id => role */
    public array $memberships = [];

    /** Services que l'utilisateur pilote (chef / adjoint / superviseur), sous-services compris. */
    public array $managed = [];

    public bool $all = false;

    public function __construct(public User $user)
    {
        $this->all = $user->hasPermission('tickets.view_all');
        $this->memberships = Member::where('user_id', $user->id)->pluck('role', 'department_id')->all();

        $departments = Department::get(['id', 'parent_id', 'head_user_id', 'supervisor_ids']);
        $roots = [];
        foreach ($departments as $d) {
            $role = $this->memberships[$d->id] ?? null;
            if ($d->head_user_id === $user->id || in_array($role, ['head', 'deputy'], true) || in_array($user->id, (array) $d->supervisor_ids, true)) {
                $roots[] = $d->id;
            }
        }
        // Descendants
        $children = $departments->groupBy('parent_id');
        $stack = $roots;
        $seen = [];
        while ($stack) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($children->get($id, collect()) as $c) {
                $stack[] = $c->id;
            }
        }
        $this->managed = array_keys($seen);
    }

    public function memberOf(): array
    {
        return array_keys($this->memberships);
    }

    public function isManager(): bool
    {
        return $this->all || $this->managed !== [];
    }

    public function scope(Builder $q): Builder
    {
        if ($this->all) {
            return $q;
        }
        $uid = $this->user->id;

        return $q->where(function ($w) use ($uid) {
            $w->where('requester_id', $uid)->orWhere('assignee_id', $uid)
                ->orWhereIn('department_id', array_merge($this->memberOf(), $this->managed))
                ->orWhereIn('requester_department_id', $this->managed);
        });
    }

    public function canSee(Ticket $t): bool
    {
        return $this->all || $t->requester_id === $this->user->id || $t->assignee_id === $this->user->id
            || in_array($t->department_id, $this->memberOf(), true) || in_array($t->department_id, $this->managed, true)
            || in_array($t->requester_department_id, $this->managed, true);
    }

    /** Peut traiter le ticket (membre du service destinataire, ou responsable de ce service). */
    public function canHandle(Ticket $t): bool
    {
        return $this->all || $t->assignee_id === $this->user->id || in_array($t->department_id, $this->memberOf(), true) || in_array($t->department_id, $this->managed, true);
    }

    /** Peut attribuer / refuser (chef, adjoint, responsable hiérarchique, direction). */
    public function canManage(Ticket $t): bool
    {
        return $this->all || in_array($t->department_id, $this->managed, true);
    }

    /** Statistiques visibles : services pilotés (ou tout pour la direction). null = tous. */
    public function statsDepartments(): ?array
    {
        return $this->all ? null : $this->managed;
    }
}
