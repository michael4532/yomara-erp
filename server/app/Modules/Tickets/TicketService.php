<?php

namespace App\Modules\Tickets;

use App\Models\AppNotification;
use App\Models\Sequence;
use App\Models\User;
use App\Modules\Tickets\Models\Department;
use App\Modules\Tickets\Models\Event;
use App\Modules\Tickets\Models\Member;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Support\Facades\DB;

/** Cycle de vie d'un ticket : chaque étape est horodatée et tracée dans l'historique. */
class TicketService
{
    /** action => [statuts de départ autorisés, statut d'arrivée, qui (requester|handler|manager)] */
    public const ACTIONS = [
        'open' => [['submitted'], 'open', 'handler'],
        'accept' => [['submitted', 'open'], 'accepted', 'handler'],
        'start' => [['submitted', 'open', 'accepted'], 'in_progress', 'handler'],
        'hold' => [['accepted', 'in_progress'], 'on_hold', 'handler'],
        'resume' => [['on_hold'], 'in_progress', 'handler'],
        'done' => [['submitted', 'open', 'accepted', 'in_progress', 'on_hold'], 'done', 'handler'],
        'reject' => [['submitted', 'open'], 'rejected', 'manager'],
        'close' => [['done'], 'closed', 'requester'],
        'reopen' => [['done', 'closed'], 'in_progress', 'requester'],
        'cancel' => [['submitted', 'open', 'accepted', 'in_progress', 'on_hold'], 'cancelled', 'requester'],
    ];

    public function create(User $user, array $data): Ticket
    {
        return DB::transaction(function () use ($user, $data) {
            $dept = Department::where('accepts_tickets', true)->where('active', true)->findOrFail($data['department_id']);
            $requesterDept = $data['requester_department_id'] ?? Member::where('user_id', $user->id)->orderByRaw("CASE role WHEN 'head' THEN 0 WHEN 'deputy' THEN 1 ELSE 2 END")->value('department_id');
            $urgency = $data['urgency'] ?? 'normal';
            $now = now();
            $t = Ticket::create([
                'number' => Sequence::next(app('tenant')->id, 'tickets', 'TCK'),
                'subject' => $data['subject'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'urgency' => $urgency,
                'status' => 'submitted',
                'requester_id' => $user->id,
                'requester_department_id' => $requesterDept,
                'department_id' => $dept->id,
                'submitted_at' => $now,
                'due_at' => $now->copy()->addHours($dept->slaHours($urgency)),
            ]);
            $this->event($t, $user, 'created', null, 'submitted', $data['description'] ?? null);

            // Le chef, ses adjoints (et le collaborateur choisi par le demandeur, s'il y en a un) sont prévenus
            $notify = Member::where('department_id', $dept->id)->whereIn('role', ['head', 'deputy'])->pluck('user_id')->push($dept->head_user_id);
            if (! empty($data['assignee_id']) && Member::where('department_id', $dept->id)->where('user_id', $data['assignee_id'])->exists()) {
                $t->update(['assignee_id' => $data['assignee_id'], 'status' => 'open', 'opened_at' => $now]);
                $this->event($t, $user, 'assign', 'submitted', 'open', null, ['assignee_id' => (int) $data['assignee_id']]);
                $notify->push((int) $data['assignee_id']);
            }
            $this->notify($t, $notify, 'Nouvelle demande '.$t->number.' ('.Ticket::URGENCIES[$urgency].')', $t->subject, $user->id);

            return $t->fresh();
        });
    }

    public function act(Ticket $t, User $user, TicketAccess $access, string $action, array $data = []): Ticket
    {
        if ($action === 'assign') {
            return $this->assign($t, $user, $access, (int) ($data['assignee_id'] ?? 0));
        }
        [$from, $to, $who] = self::ACTIONS[$action] ?? throw new \DomainException('Action inconnue.');
        $allowed = match ($who) {
            'requester' => $t->requester_id === $user->id || $access->canManage($t),
            'manager' => $access->canManage($t),
            default => $access->canHandle($t),
        };
        if (! $allowed) {
            throw new \DomainException('Vous n\'avez pas le droit d\'effectuer cette action sur ce ticket.');
        }
        if (! in_array($t->status, $from, true)) {
            throw new \DomainException('Action impossible : le ticket est « '.Ticket::STATUSES[$t->status].' ».');
        }
        if (in_array($action, ['hold', 'reject'], true) && empty($data['reason'])) {
            throw new \DomainException($action === 'hold' ? 'Indiquez la raison de la mise en attente.' : 'Indiquez le motif du refus.');
        }

        return DB::transaction(function () use ($t, $user, $action, $to, $data) {
            $now = now();
            $old = $t->status;
            $changes = ['status' => $to];
            // Fin d'une attente
            if ($old === 'on_hold' && $t->hold_since) {
                $changes['hold_seconds'] = $t->hold_seconds + (int) $t->hold_since->diffInSeconds($now);
                $changes['hold_since'] = null;
            }
            switch ($action) {
                case 'open':
                    $changes['opened_at'] = $t->opened_at ?? $now;
                    break;
                case 'accept':
                    $changes += ['opened_at' => $t->opened_at ?? $now, 'accepted_at' => $now, 'assignee_id' => $t->assignee_id ?? $user->id];
                    break;
                case 'start':
                    $changes += ['opened_at' => $t->opened_at ?? $now, 'accepted_at' => $t->accepted_at ?? $now, 'started_at' => $t->started_at ?? $now, 'assignee_id' => $t->assignee_id ?? $user->id];
                    break;
                case 'hold':
                    $changes += ['hold_since' => $now, 'hold_reason' => $data['reason'], 'purchase_requested' => (bool) ($data['purchase'] ?? false)];
                    break;
                case 'resume':
                    $changes['hold_reason'] = null;
                    break;
                case 'done':
                    $changes += ['done_at' => $now, 'resolution' => $data['resolution'] ?? $data['reason'] ?? null, 'assignee_id' => $t->assignee_id ?? $user->id,
                        'opened_at' => $t->opened_at ?? $now, 'accepted_at' => $t->accepted_at ?? $now, 'started_at' => $t->started_at ?? $now];
                    break;
                case 'close':
                    $changes += ['closed_at' => $now, 'rating' => isset($data['rating']) ? max(1, min(5, (int) $data['rating'])) : null];
                    break;
                case 'reopen':
                    $changes += ['done_at' => null, 'closed_at' => null, 'rating' => null];
                    break;
            }
            $t->update($changes);
            $body = $data['reason'] ?? $data['resolution'] ?? $data['comment'] ?? null;
            $this->event($t, $user, $action === 'hold' ? 'hold' : 'status', $old, $to, $body, array_filter([
                'purchase' => $action === 'hold' && ! empty($data['purchase']) ? true : null,
                'rating' => $changes['rating'] ?? null,
            ]));

            $label = Ticket::STATUSES[$to];
            if ($t->requester_id !== $user->id) {
                $msg = match ($action) {
                    'hold' => 'En attente : '.$data['reason'],
                    'done' => 'Terminé'.(! empty($changes['resolution']) ? ' : '.$changes['resolution'] : '').' — merci de confirmer.',
                    'reject' => 'Refusé : '.$data['reason'],
                    default => $label,
                };
                $this->notify($t, [$t->requester_id], $t->number.' · '.$label, $msg, $user->id);
            } elseif (in_array($action, ['reopen', 'cancel', 'close'], true)) {
                $this->notify($t, [$t->assignee_id, $t->department?->head_user_id], $t->number.' · '.$label, $body ?? $t->subject, $user->id);
            }

            return $t->fresh();
        });
    }

    public function assign(Ticket $t, User $user, TicketAccess $access, int $assigneeId): Ticket
    {
        if (! $access->canManage($t) && ! ($assigneeId === $user->id && $access->canHandle($t))) {
            throw new \DomainException('Seul le chef de service (ou un responsable) peut attribuer ce ticket.');
        }
        if (! in_array($t->status, Ticket::ACTIVE, true)) {
            throw new \DomainException('Ce ticket est clos.');
        }
        if (! Member::where('department_id', $t->department_id)->where('user_id', $assigneeId)->exists()) {
            throw new \DomainException('Cette personne ne fait pas partie du service destinataire.');
        }
        $old = $t->status;
        $t->update([
            'assignee_id' => $assigneeId,
            'status' => $old === 'submitted' ? 'open' : $old,
            'opened_at' => $t->opened_at ?? now(),
        ]);
        $this->event($t, $user, 'assign', $old, $t->status, null, ['assignee_id' => $assigneeId]);
        $this->notify($t, [$assigneeId], 'Ticket attribué : '.$t->number.' ('.Ticket::URGENCIES[$t->urgency].')', $t->subject, $user->id);

        return $t->fresh();
    }

    public function comment(Ticket $t, User $user, TicketAccess $access, string $body, bool $internal): Event
    {
        if ($internal && ! $access->canHandle($t)) {
            $internal = false;
        }
        $e = $this->event($t, $user, $internal ? 'note' : 'comment', null, null, $body);
        if (! $internal) {
            $to = $t->requester_id === $user->id ? [$t->assignee_id ?? $t->department?->head_user_id] : [$t->requester_id];
            $this->notify($t, $to, 'Message sur '.$t->number, mb_substr($body, 0, 200), $user->id);
        }
        $t->touch();

        return $e;
    }

    protected function event(Ticket $t, User $user, string $type, ?string $from, ?string $to, ?string $body = null, array $meta = []): Event
    {
        return Event::create(['ticket_id' => $t->id, 'user_id' => $user->id, 'type' => $type, 'from_status' => $from, 'to_status' => $to, 'body' => $body, 'meta' => $meta ?: null, 'created_at' => now()]);
    }

    protected function notify(Ticket $t, iterable $users, string $title, ?string $body, ?int $except): void
    {
        AppNotification::send($t->company_id, $users, 'ticket', $title, $body, 'tickets', (string) $t->id, $except);
    }
}
