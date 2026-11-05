<?php

namespace App\Modules\Planning;

use App\Models\Company;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $l = app(PlanningService::class)->load($company, now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString());
        $p = 'planning.view|planning.manage';

        return [
            ['key' => 'pl_occupation', 'label' => 'Occupation de l\'équipe cette semaine (%)', 'value' => $l['team']['occupation'] ?? 0, 'format' => 'number', 'icon' => 'view_timeline', 'permission' => $p],
            ['key' => 'pl_overload', 'label' => 'Personnes en surcharge cette semaine', 'value' => collect($l['users'])->where('overload_days', '>', 0)->count(), 'format' => 'number', 'icon' => 'warning', 'permission' => $p],
        ];
    }
}
