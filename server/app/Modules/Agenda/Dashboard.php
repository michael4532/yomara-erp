<?php

namespace App\Modules\Agenda;

use App\Models\Company;
use App\Modules\Agenda\Models\Event;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $u = auth()->user();
        $n = 0;
        if ($u) {
            foreach (app(AgendaService::class)->eventsFor($u) as $e) {
                $n += count(Recurrence::occurrences($e->starts_at, $e->ends_at, $e->recurrence, (array) $e->exceptions, today(), today()->endOfDay(), 20));
            }
        }

        return [
            ['key' => 'ag_today', 'label' => 'Mes événements aujourd\'hui', 'value' => $n, 'format' => 'number', 'icon' => 'calendar_month', 'permission' => 'agenda.use|agenda.manage'],
        ];
    }
}
