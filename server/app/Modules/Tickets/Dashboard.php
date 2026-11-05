<?php

namespace App\Modules\Tickets;

use App\Models\Company;
use App\Modules\Tickets\Models\Ticket;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }
        $access = new TicketAccess($user);
        $mine = Ticket::where('assignee_id', $user->id)->whereIn('status', Ticket::ACTIVE)->count();
        $visible = $access->scope(Ticket::query())->whereIn('status', Ticket::ACTIVE);

        return [
            ['key' => 'tk_mine', 'label' => 'Tickets à traiter (moi)', 'value' => $mine, 'format' => 'number', 'icon' => 'confirmation_number'],
            ['key' => 'tk_open', 'label' => 'Tickets en cours (mon périmètre)', 'value' => (clone $visible)->count(), 'format' => 'number', 'icon' => 'confirmation_number'],
            ['key' => 'tk_late', 'label' => 'Tickets en retard', 'value' => (clone $visible)->where('due_at', '<', now())->count(), 'format' => 'number', 'icon' => 'alarm'],
        ];
    }
}
