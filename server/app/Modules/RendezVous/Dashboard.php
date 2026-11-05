<?php

namespace App\Modules\RendezVous;

use App\Models\Company;
use App\Modules\RendezVous\Models\Appointment;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $tz = $company->settings['timezone'] ?? config('erp.defaults.timezone', 'UTC');
        $from = now($tz)->startOfDay()->utc()->toDateTimeString();
        $to = now($tz)->endOfDay()->utc()->toDateTimeString();

        return [
            ['key' => 'rdv_today', 'label' => 'Rendez-vous du jour', 'value' => Appointment::whereIn('status', Appointment::BUSY)->whereBetween('starts_at', [$from, $to])->count(), 'format' => 'number', 'icon' => 'event_available'],
            ['key' => 'rdv_pending', 'label' => 'Rendez-vous à confirmer', 'value' => Appointment::where('status', 'pending')->where('starts_at', '>', now()->toDateTimeString())->count(), 'format' => 'number', 'icon' => 'event_note', 'permission' => 'rendez_vous.manage'],
        ];
    }
}
