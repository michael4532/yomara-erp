<?php

namespace App\Modules\Helpdesk;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $s = app(HelpdeskService::class)->stats();
        $p = 'helpdesk.view|helpdesk.agent|helpdesk.manage';

        return [
            ['key' => 'hd_open', 'label' => 'Tickets clients ouverts', 'value' => $s['open'], 'format' => 'number', 'icon' => 'support_agent', 'permission' => $p],
            ['key' => 'hd_overdue', 'label' => 'Tickets en retard (SLA)', 'value' => $s['overdue'], 'format' => 'number', 'icon' => 'warning', 'permission' => $p],
            ['key' => 'hd_sla', 'label' => 'Délais respectés sur 30 jours (%)', 'value' => $s['sla_compliance'] ?? 100, 'format' => 'number', 'icon' => 'verified', 'permission' => $p],
        ];
    }
}
