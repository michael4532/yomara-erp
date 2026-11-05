<?php

namespace App\Modules\Pointage;

use App\Models\Company;
use App\Modules\Pointage\Models\Badge;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $tz = $company->settings['timezone'] ?? config('app.timezone');
        $today = now($tz)->toDateString();
        $days = app(PointageService::class)->days($company, Badge::where('active', true)->get(), $today, $today);
        $count = fn (array $st) => collect($days)->filter(fn ($r) => in_array($r['days'][$today]['status'], $st, true))->count();
        $perm = 'pointage.view|pointage.manage';

        return [
            ['key' => 'pt_present', 'label' => 'Présents', 'value' => $count(['in', 'present', 'late', 'incomplete']), 'format' => 'number', 'icon' => 'fingerprint', 'permission' => $perm],
            ['key' => 'pt_late', 'label' => 'En retard aujourd\'hui', 'value' => $count(['late']) + collect($days)->filter(fn ($r) => $r['days'][$today]['late'] > 0 && $r['days'][$today]['status'] !== 'late')->count(), 'format' => 'number', 'icon' => 'schedule', 'permission' => $perm],
            ['key' => 'pt_absent', 'label' => 'Absents non justifiés', 'value' => $count(['absent']), 'format' => 'number', 'icon' => 'person_off', 'permission' => $perm],
        ];
    }
}
