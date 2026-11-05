<?php

namespace App\Modules\Flotte;

use App\Http\Controllers\Controller;
use App\Support\Records\RecordController;
use App\Support\Records\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlotteController extends Controller
{
    public function __construct(private FlotteHooks $h) {}

    public function dashboard()
    {
        return ['data' => $this->h->dashboard(app('tenant'))];
    }

    /** Véhicules libres sur une période (pour choisir à la demande ou à l'attribution). */
    public function availability(Request $r)
    {
        $d = $r->validate(['start' => 'required|date', 'end' => 'required|date', 'except' => 'nullable|integer']);
        $c = app('tenant');
        $busy = DB::table('fl_trips')->where('company_id', $c->id)->whereIn('status', ['approved', 'out'])->whereNotNull('vehicle_id')
            ->when($d['except'] ?? null, fn ($q, $e) => $q->where('id', '!=', $e))
            ->where('planned_start', '<', date('Y-m-d H:i:s', strtotime($d['end'])))->where('planned_end', '>', date('Y-m-d H:i:s', strtotime($d['start'])))->pluck('vehicle_id')->all();

        return ['data' => DB::table('fl_vehicles')->where('company_id', $c->id)->whereNotIn('status', ['retired'])->orderBy('title')->get(['id', 'title', 'plate', 'kind', 'seats', 'status'])
            ->map(fn ($v) => (array) $v + ['free' => ! in_array($v->id, $busy) && $v->status !== 'maintenance'])->values()];
    }

    public function action(Request $r, int $id, string $action)
    {
        $in = $r->validate([
            'vehicle_id' => 'nullable|integer', 'driver_id' => 'nullable|integer', 'note' => 'nullable|string|max:255', 'km' => 'nullable|integer|min:0',
            'fuel_level' => 'nullable|in:'.implode(',', array_keys(FlotteHooks::FUEL_LEVELS)), 'report' => 'nullable|string|max:5000', 'issue' => 'nullable|boolean',
            'fuel_amount' => 'nullable|numeric|min:0', 'fuel_liters' => 'nullable|numeric|min:0', 'fuel_payment' => 'nullable|in:'.implode(',', array_keys(FlotteHooks::PAYMENTS)), 'at' => 'nullable|date',
        ]);
        try {
            $row = DB::transaction(fn () => $this->h->action(app('tenant'), $r->user(), $id, $action, $in));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $res = Records::resource(__DIR__.'/records.php', 'trips');

        return ['data' => app(RecordController::class)->present($res, [$row])[0]];
    }
}
