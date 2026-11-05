<?php

namespace App\Modules\Evenements;

use App\Models\Company;
use App\Modules\Evenements\Models\Booking;
use App\Modules\Evenements\Models\Event;

class Dashboard
{
    public function __invoke(Company $company): array
    {
        $next = Event::whereIn('status', ['open', 'closed', 'draft'])->where('end_date', '>=', now()->toDateString())->orderBy('start_date')->first();
        $out = [];
        if ($next) {
            $s = app(EventService::class)->stats($next);
            $out[] = ['key' => 'ev_occupancy', 'label' => 'Stands réservés — '.$next->name, 'value' => $s['occupancy'], 'format' => 'percent', 'icon' => 'storefront', 'permission' => 'evenements.view|evenements.sell|evenements.manage'];
        }
        $out[] = ['key' => 'ev_options', 'label' => 'Options de stands à confirmer', 'value' => Booking::where('status', 'option')->count(), 'format' => 'number', 'icon' => 'hourglass_top', 'permission' => 'evenements.view|evenements.sell|evenements.manage'];

        return $out;
    }
}
