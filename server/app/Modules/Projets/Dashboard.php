<?php

namespace App\Modules\Projets;

use App\Models\Company;
use App\Modules\Projets\Models\Project;
use App\Modules\Projets\Models\Task;
use App\Modules\Projets\Models\Timesheet;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        return [
            ['key' => 'prj_active', 'label' => 'Projets en cours', 'value' => Project::where('status', 'active')->count(), 'format' => 'number', 'icon' => 'task_alt'],
            ['key' => 'prj_late', 'label' => 'Tâches en retard', 'value' => Task::where('status', '!=', 'done')->whereNotNull('due_date')->where('due_date', '<', now()->toDateString())->count(), 'format' => 'number', 'icon' => 'alarm'],
            ['key' => 'prj_week', 'label' => 'Heures saisies cette semaine', 'value' => round((float) Timesheet::whereBetween('date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])->sum('hours'), 1), 'format' => 'number', 'icon' => 'schedule'],
        ];
    }
}
