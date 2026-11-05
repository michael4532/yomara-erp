<?php

namespace App\Modules\RendezVous;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\RendezVous\Models\Appointment;
use App\Modules\RendezVous\Models\Service;
use App\Modules\RendezVous\Models\Staff;
use Carbon\Carbon;
use Illuminate\Http\Request;

class RendezVousController extends Controller
{
    public function __construct(protected SlotService $slots, protected BookingService $booking) {}

    /** Créneaux libres d'une prestation (aide à la saisie dans l'application). */
    public function slots(Request $r)
    {
        $d = $r->validate(['service_id' => 'required|uuid', 'date' => 'required|date', 'staff_id' => 'nullable|uuid', 'ignore' => 'nullable|uuid']);
        $service = Service::findOrFail($d['service_id']);

        return ['data' => $this->slots->slots(app('tenant'), $service, $d['date'], $d['staff_id'] ?? null, false, $d['ignore'] ?? null)];
    }

    public function invoice(string $id)
    {
        try {
            $doc = $this->booking->invoice(app('tenant'), Appointment::findOrFail($id));
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return ['invoice' => $doc->toSyncArray()];
    }

    /** Membres de l'équipe pouvant être liés à un utilisateur. */
    public function users()
    {
        return ['users' => User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])];
    }

    public function info()
    {
        $c = app('tenant');

        return ['url' => url('/rdv/'.$c->slug), 'settings' => $this->slots->settings($c)];
    }

    public function stats(Request $r)
    {
        $d = $r->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);
        $from = Carbon::parse($d['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($d['to'] ?? now())->endOfDay();
        $rows = Appointment::whereBetween('starts_at', [$from->toDateTimeString(), $to->toDateTimeString()])->get();
        $past = $rows->filter(fn ($a) => $a->starts_at->lt(now()) && $a->status !== 'cancelled');
        $services = Service::pluck('name', 'id');
        $staff = Staff::pluck('name', 'id');
        // Taux d'occupation : minutes réservées / minutes ouvertes de l'équipe sur la période
        $open = 0;
        foreach (Staff::where('active', true)->get() as $s) {
            for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
                foreach ($s->rangesFor($day->dayOfWeekIso) as [$a, $b]) {
                    $open += Carbon::parse($a)->diffInMinutes(Carbon::parse($b));
                }
            }
        }
        $booked = $rows->whereIn('status', Appointment::BUSY)->sum(fn ($a) => $a->starts_at->diffInMinutes($a->ends_at));

        return [
            'total' => $rows->where('status', '!=', 'cancelled')->count(),
            'online' => $rows->whereIn('source', ['online', 'portal'])->count(),
            'done' => $rows->where('status', 'done')->count(),
            'no_show' => $rows->where('status', 'no_show')->count(),
            'cancelled' => $rows->where('status', 'cancelled')->count(),
            'no_show_rate' => $past->count() ? round(100 * $past->where('status', 'no_show')->count() / $past->count(), 1) : null,
            'occupancy' => $open ? round(100 * $booked / $open, 1) : null,
            'revenue' => round($rows->where('status', 'done')->sum('price'), 2),
            'services' => $rows->where('status', '!=', 'cancelled')->groupBy('service_id')->map(fn ($g, $id) => ['name' => $services[$id] ?? 'Autre', 'count' => $g->count(), 'revenue' => round($g->where('status', 'done')->sum('price'), 2)])->sortByDesc('count')->values(),
            'staff' => $rows->where('status', '!=', 'cancelled')->groupBy('staff_id')->map(fn ($g, $id) => ['name' => $staff[$id] ?? 'Non attribué', 'count' => $g->count(), 'no_show' => $g->where('status', 'no_show')->count()])->sortByDesc('count')->values(),
            'by_hour' => $rows->where('status', '!=', 'cancelled')->groupBy(fn ($a) => (int) $a->starts_at->copy()->timezone(Appointment::tz())->format('G'))->map->count()->sortKeys(),
        ];
    }
}
