<?php

namespace App\Modules\Logistique;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Modules\Logistique\Models\Delivery;
use App\Modules\Logistique\Models\DeliveryEvent;

/** Page publique de suivi d'une livraison (lien envoyé au client). */
class TrackingController extends Controller
{
    public function show(string $token)
    {
        $d = Delivery::withoutGlobalScopes()->where('tracking_token', $token)->whereNull('deleted_at')->first();
        abort_unless($d, 404);
        $company = Company::find($d->company_id);
        abort_unless($company && $company->hasModule('logistique'), 404);
        $events = DeliveryEvent::withoutGlobalScopes()->where('delivery_id', $d->id)->orderBy('id')->get();
        $driver = $d->driver_id ? User::find($d->driver_id) : null;

        return response()->view('logistique.tracking', [
            'd' => $d, 'company' => $company, 'events' => $events,
            'driver' => $driver ? explode(' ', $driver->name)[0] : null,
        ])->header('X-Robots-Tag', 'noindex');
    }
}
