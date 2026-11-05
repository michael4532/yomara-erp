<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** « Ma journée » : agenda du jour et du mois (pastilles du calendrier), tâches de projet, échéances, tâches personnelles. */
class MyDayController extends Controller
{
    public function __invoke(Request $r)
    {
        $c = app('tenant');
        $u = $r->user();
        $day = Carbon::parse($r->query('date', now()->toDateString()))->startOfDay();
        $month = Carbon::parse(($r->query('month') ?: $day->format('Y-m')).'-01')->startOfMonth();
        $out = ['date' => $day->toDateString(), 'events' => [], 'month_days' => (object) [], 'tasks' => [], 'due' => null, 'todos_open' => DB::table('user_todos')->where('user_id', $u->id)->whereNull('done_at')->count()];

        if ($c->hasModule('agenda') && $u->hasPermission('agenda.use|agenda.manage')) {
            $svc = app(\App\Modules\Agenda\AgendaService::class);
            $from = $month->copy()->startOfWeek();
            $to = $month->copy()->endOfMonth()->endOfWeek();
            $from = $from->min($day->copy());
            $to = $to->max($day->copy()->endOfDay());
            $items = collect($svc->overlay($c, $u, $from, $to));
            $days = [];
            foreach ($items as $e) {
                for ($d = Carbon::parse($e['starts_at'])->startOfDay(); $d->lte(Carbon::parse($e['ends_at'])) && $d->lte($to); $d->addDay()) {
                    $days[$d->toDateString()] = ($days[$d->toDateString()] ?? 0) + 1;
                }
            }
            $out['month_days'] = (object) $days;
            $out['events'] = $items->filter(fn ($e) => Carbon::parse($e['starts_at'])->lte($day->copy()->endOfDay()) && Carbon::parse($e['ends_at'])->gte($day))
                ->sortBy('starts_at')->values()->take(30)->all();
        }
        if ($c->hasModule('projets') && $u->hasPermission('projets.view|projets.manage')) {
            $out['tasks'] = DB::table('prj_tasks as t')->leftJoin('prj_projects as p', 'p.id', '=', 't.project_id')->where('t.company_id', $c->id)->whereNull('t.deleted_at')
                ->where('t.assignee_id', $u->id)->where('t.status', '!=', 'done')->where(fn ($w) => $w->whereNull('t.due_date')->orWhere('t.due_date', '<=', $day->copy()->addDays(3)->toDateString()))
                ->orderByRaw('t.due_date IS NULL')->orderBy('t.due_date')->limit(8)->get(['t.id', 't.title', 't.due_date', 't.status', 'p.name as project'])->all();
        }
        if ($c->hasModule('ventes') && $u->hasPermission('ventes.view')) {
            $q = \App\Modules\Ventes\Models\SalesDocument::query()->where('status', 'validated')->where('type', 'invoice')->where('payment_status', '!=', 'paid')
                ->whereDate('due_date', $day->toDateString());
            $out['due'] = ['count' => (clone $q)->count(), 'amount' => round((float) (clone $q)->sum(DB::raw('total - amount_paid')), 2)];
        }

        return $out;
    }
}
