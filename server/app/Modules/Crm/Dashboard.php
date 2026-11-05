<?php

namespace App\Modules\Crm;

use App\Models\Company;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Lead;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $open = Lead::where('status', 'open');

        return [
            ['key' => 'crm_open', 'label' => 'Opportunités en cours', 'value' => (clone $open)->count(), 'format' => 'number', 'icon' => 'handshake'],
            ['key' => 'crm_weighted', 'label' => 'Pipeline pondéré', 'value' => round((float) (clone $open)->selectRaw('SUM(expected_revenue * probability / 100) as w')->value('w'), 2), 'format' => 'money', 'icon' => 'trending_up'],
            ['key' => 'crm_late', 'label' => 'Relances en retard', 'value' => Activity::where('done', false)->where('due_at', '<', now())->count(), 'format' => 'number', 'icon' => 'alarm'],
        ];
    }
}
