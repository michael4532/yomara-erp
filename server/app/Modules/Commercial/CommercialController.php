<?php

namespace App\Modules\Commercial;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommercialController extends Controller
{
    public function __construct(private CommercialHooks $h) {}

    public function dashboard(Request $r)
    {
        $m = $r->validate(['month' => 'nullable|date_format:Y-m'])['month'] ?? null;

        return ['data' => $this->h->dashboard(app('tenant'), $r->user(), $m)];
    }

    public function vehicle(Request $r, int $id)
    {
        $in = $r->validate(['start' => 'nullable|date_format:H:i', 'end' => 'nullable|date_format:H:i', 'vehicle_id' => ['nullable', 'integer', \App\Support\TenantRef::of('fl_vehicles')], 'passengers' => 'nullable|string|max:255']);
        try {
            $trip = DB::transaction(fn () => $this->h->requestVehicle(app('tenant'), $r->user(), $id, $in));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => ['trip_id' => $trip['id'], 'number' => $trip['number'], 'status' => $trip['status']]];
    }
}
