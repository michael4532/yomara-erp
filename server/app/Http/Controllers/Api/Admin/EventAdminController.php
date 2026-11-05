<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Super admin : tous les événements de la plateforme (inscriptions, présence, stands, avis, recettes). */
class EventAdminController extends Controller
{
    public function index(Request $r)
    {
        if (! Schema::hasTable('ev_events')) {
            return ['data' => [], 'totals' => []];
        }
        $q = DB::table('ev_events')->whereNull('deleted_at');
        if ($r->filled('company_id')) {
            $q->where('company_id', (int) $r->input('company_id'));
        }
        if ($r->input('period') === 'upcoming') {
            $q->where('end_date', '>=', now()->toDateString());
        } elseif ($r->input('period') === 'past') {
            $q->where('end_date', '<', now()->toDateString());
        }
        if ($s = trim((string) $r->input('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('city', 'like', "%$s%"));
        }
        $events = $q->orderByDesc('start_date')->limit(300)->get();
        $ids = $events->pluck('id')->all();
        $companies = Company::whereIn('id', $events->pluck('company_id')->unique())->pluck('name', 'id');
        $tickets = Schema::hasTable('ev_tickets') ? DB::table('ev_tickets')->whereIn('event_id', $ids)->whereNull('deleted_at')->where('status', 'valid')
            ->selectRaw('event_id, count(*) n, sum(case when checked_in_at is not null then 1 else 0 end) ci')->groupBy('event_id')->get()->keyBy('event_id') : collect();
        $sales = Schema::hasTable('ev_ticket_orders') ? DB::table('ev_ticket_orders')->whereIn('event_id', $ids)->whereNull('deleted_at')->where('status', 'paid')
            ->selectRaw('event_id, sum(total) t')->groupBy('event_id')->pluck('t', 'event_id') : collect();
        $fb = Schema::hasTable('ev_feedback') ? DB::table('ev_feedback')->whereIn('event_id', $ids)->selectRaw('event_id, count(*) n, avg(rating) r')->groupBy('event_id')->get()->keyBy('event_id') : collect();
        $spaces = DB::table('ev_spaces')->whereIn('event_id', $ids)->whereNull('deleted_at')->selectRaw('event_id, count(*) n')->groupBy('event_id')->pluck('n', 'event_id');
        $taken = [];
        $bookings = [];
        foreach (DB::table('ev_bookings')->whereIn('event_id', $ids)->whereNull('deleted_at')->whereIn('status', ['option', 'confirmed'])->get(['event_id', 'space_ids', 'status']) as $b) {
            foreach ((array) json_decode((string) $b->space_ids, true) as $sid) {
                $taken[$b->event_id][$sid] = true;
            }
            $bookings[$b->event_id][$b->status] = ($bookings[$b->event_id][$b->status] ?? 0) + 1;
        }
        $rows = $events->map(function ($e) use ($companies, $tickets, $sales, $fb, $spaces, $taken, $bookings) {
            $n = (int) ($spaces[$e->id] ?? 0);
            $t = $tickets[$e->id] ?? null;
            $f = $fb[$e->id] ?? null;

            return [
                'id' => $e->id, 'name' => $e->name, 'company_id' => $e->company_id, 'company' => $companies[$e->company_id] ?? '—',
                'city' => $e->city, 'venue' => $e->venue, 'start_date' => substr((string) $e->start_date, 0, 10), 'end_date' => substr((string) $e->end_date, 0, 10), 'status' => $e->status,
                'registrations' => (int) ($t->n ?? 0), 'checked_in' => (int) ($t->ci ?? 0), 'ticket_revenue' => round((float) ($sales[$e->id] ?? 0), 2),
                'spaces' => $n, 'spaces_taken' => count($taken[$e->id] ?? []), 'fill_rate' => $n ? round(100 * count($taken[$e->id] ?? []) / $n, 1) : null,
                'bookings_confirmed' => $bookings[$e->id]['confirmed'] ?? 0, 'bookings_option' => $bookings[$e->id]['option'] ?? 0,
                'feedback' => (int) ($f->n ?? 0), 'rating' => $f && $f->r !== null ? round((float) $f->r, 2) : null,
            ];
        })->values();

        return ['data' => $rows, 'totals' => [
            'events' => $rows->count(), 'companies' => $rows->pluck('company_id')->unique()->count(),
            'registrations' => $rows->sum('registrations'), 'checked_in' => $rows->sum('checked_in'),
            'ticket_revenue' => round($rows->sum('ticket_revenue'), 2), 'spaces' => $rows->sum('spaces'), 'spaces_taken' => $rows->sum('spaces_taken'),
            'feedback' => $rows->sum('feedback'),
        ]];
    }
}
