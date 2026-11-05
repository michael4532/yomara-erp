<?php

namespace App\Modules\Recrutement;

use App\Models\Company;
use App\Modules\Recrutement\Models\Application;
use App\Modules\Recrutement\Models\Interview;
use App\Modules\Recrutement\Models\Job;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $p = 'recrutement.view|recrutement.manage';

        return [
            ['key' => 'rc_open_jobs', 'label' => 'Offres d\'emploi ouvertes', 'value' => Job::where('status', 'open')->count(), 'format' => 'number', 'icon' => 'person_search', 'permission' => $p],
            ['key' => 'rc_new', 'label' => 'Nouvelles candidatures', 'value' => Application::where('stage', 'new')->count(), 'format' => 'number', 'icon' => 'person_add', 'permission' => $p],
            ['key' => 'rc_interviews', 'label' => 'Entretiens à venir', 'value' => Interview::where('status', 'planned')->where('starts_at', '>=', now()->startOfDay())->count(), 'format' => 'number', 'icon' => 'event', 'permission' => $p.'|recrutement.interview'],
        ];
    }
}
