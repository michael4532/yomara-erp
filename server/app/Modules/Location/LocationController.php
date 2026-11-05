<?php

namespace App\Modules\Location;

use App\Http\Controllers\Controller;
use App\Support\Records\RecordController;
use App\Support\Records\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocationController extends Controller
{
    public function __construct(private LocationHooks $h) {}

    public function dashboard()
    {
        return ['data' => $this->h->dashboard(app('tenant'))];
    }

    /** Matériel disponible sur une période (quantité libre par article). */
    public function availability(Request $r)
    {
        $d = $r->validate(['start' => 'required|date', 'end' => 'required|date|after:start', 'except' => 'nullable|integer']);
        $c = app('tenant');
        $start = date('Y-m-d H:i:s', strtotime($d['start']));
        $end = date('Y-m-d H:i:s', strtotime($d['end']));

        return ['data' => DB::table('lo_items')->where('company_id', $c->id)->where('status', '!=', 'retired')->orderBy('category')->orderBy('title')->get()
            ->map(fn ($i) => ['id' => $i->id, 'title' => $i->title, 'category' => $i->category, 'quantity' => (int) $i->quantity, 'status' => $i->status, 'rate_day' => (float) $i->rate_day,
                'free' => $i->status === 'available' ? max(0, (int) $i->quantity - LocationHooks::used($c->id, $i->id, $start, $end, $d['except'] ?? null)) : 0])->values()];
    }

    public function action(Request $r, int $id, string $action)
    {
        $in = $r->validate([
            'at' => 'nullable|date', 'deposit_received' => 'nullable|boolean', 'deposit_method' => 'nullable|in:'.implode(',', array_keys(LocationHooks::DEPOSIT_METHODS)),
            'deposit_kept' => 'nullable|numeric|min:0', 'late_fee' => 'nullable|numeric|min:0', 'damage_fee' => 'nullable|numeric|min:0',
            'conditions' => 'nullable|array', 'conditions.*' => 'nullable|string|max:200',
            'lines' => 'nullable|array', 'lines.*.condition_in' => 'nullable|string|max:200', 'lines.*.damage_fee' => 'nullable|numeric|min:0',
        ]);
        try {
            $row = DB::transaction(fn () => $this->h->action(app('tenant'), $r->user(), $id, $action, $in));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['data' => app(RecordController::class)->present(Records::resource(__DIR__.'/records.php', 'rentals'), [$row])[0]];
    }
}
