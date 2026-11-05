<?php

namespace App\Modules\Projets;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Modules\Projets\Models\Milestone;
use App\Modules\Projets\Models\Project;
use App\Modules\Projets\Models\Task;
use App\Modules\Projets\Models\Timesheet;
use App\Modules\Ventes\Models\SalesDocument;
use App\Services\EntityWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProjetsController extends Controller
{
    public function users()
    {
        return ['users' => User::where('company_id', app('tenant')->id)->where('is_active', true)->orderBy('name')->get(['id', 'name'])];
    }

    /** Indicateurs par projet : avancement, temps, budget, à facturer. */
    public function stats(Request $r)
    {
        $today = now()->toDateString();
        $projects = Project::query()->when($r->query('project_id'), fn ($q, $id) => $q->where('id', $id))->get();
        $ids = $projects->pluck('id');
        $tasks = Task::whereIn('project_id', $ids)->get(['id', 'project_id', 'status', 'due_date', 'estimate_hours', 'assignee_id']);
        $times = Timesheet::whereIn('project_id', $ids)->get(['project_id', 'task_id', 'user_id', 'hours', 'billable', 'invoice_id', 'pack_id', 'date']);
        $milestones = Milestone::whereIn('project_id', $ids)->get();
        $users = User::where('company_id', app('tenant')->id)->pluck('name', 'id');

        $out = [];
        foreach ($projects as $p) {
            $t = $tasks->where('project_id', $p->id);
            $h = $times->where('project_id', $p->id);
            $done = $t->where('status', 'done')->count();
            $hours = round($h->sum('hours'), 2);
            $billable = $h->where('billable', true);
            $toInvoice = round($billable->whereNull('invoice_id')->whereNull('pack_id')->sum('hours'), 2);
            $consumed = $p->billing_mode === 'time' ? round($hours * $p->hourly_rate, 2) : null;
            $ms = $milestones->where('project_id', $p->id);
            $out[] = [
                'id' => $p->id,
                'tasks' => $t->count(), 'done' => $done,
                'late' => $t->where('status', '!=', 'done')->filter(fn ($x) => $x->due_date && $x->due_date->toDateString() < $today)->count(),
                'progress' => $t->count() ? round($done * 100 / $t->count()) : 0,
                'estimate_hours' => round($t->sum('estimate_hours'), 2),
                'hours' => $hours,
                'billable_hours' => round($billable->sum('hours'), 2),
                'to_invoice_hours' => $toInvoice,
                'pack_hours' => round($billable->whereNotNull('pack_id')->sum('hours'), 2),
                'to_invoice_amount' => $p->billing_mode === 'time' ? round($toInvoice * $p->hourly_rate, 2) : round($ms->where('status', 'reached')->whereNull('invoice_id')->sum('amount'), 2),
                'invoiced_amount' => round($p->billing_mode === 'time' ? $billable->whereNotNull('invoice_id')->sum('hours') * $p->hourly_rate : $ms->whereNotNull('invoice_id')->sum('amount'), 2),
                'consumed_amount' => $consumed,
                'budget_used' => $p->budget_hours > 0 ? round($hours * 100 / $p->budget_hours) : ($p->budget_amount > 0 && $consumed !== null ? round($consumed * 100 / $p->budget_amount) : null),
                'by_user' => $h->groupBy('user_id')->map(fn ($g, $uid) => ['user_id' => (int) $uid, 'name' => $users[$uid] ?? '—', 'hours' => round($g->sum('hours'), 2)])->sortByDesc('hours')->values(),
            ];
        }
        $week = Timesheet::where('user_id', $r->user()->id)->whereBetween('date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()])->sum('hours');

        return ['projects' => $out, 'my_week_hours' => round((float) $week, 2), 'my_open_tasks' => Task::where('assignee_id', $r->user()->id)->where('status', '!=', 'done')->count()];
    }

    /**
     * Facture brouillon : temps non facturés (mode « temps passé ») ou jalon atteint (forfait).
     * Les temps / jalons facturés sont verrouillés ; ils redeviennent facturables si la facture est supprimée ou annulée.
     */
    public function invoice(Request $r, string $id, EntityWriter $writer)
    {
        $p = Project::findOrFail($id);
        $d = $r->validate(['milestone_id' => 'nullable|uuid', 'until' => 'nullable|date', 'group_by' => 'nullable|in:task,user,line']);
        $company = app('tenant');
        if (! $company->hasModule('ventes')) {
            return response()->json(['message' => 'Le module Ventes & Facturation est nécessaire pour facturer.'], 422);
        }
        if (! $p->partner_id) {
            return response()->json(['message' => 'Associez d\'abord un client au projet.'], 422);
        }
        $taxRate = (float) ($company->settings['tax_rate'] ?? 18);
        $lines = [];
        $timesheets = collect();
        $milestone = null;
        if (! empty($d['milestone_id'])) {
            $milestone = Milestone::where('project_id', $p->id)->findOrFail($d['milestone_id']);
            if ($milestone->invoice_id) {
                return response()->json(['message' => 'Ce jalon est déjà facturé.'], 422);
            }
            $lines[] = ['description' => $p->name.' — '.$milestone->name, 'quantity' => 1, 'unit' => 'forfait', 'unit_price' => $milestone->amount, 'tax_rate' => $taxRate];
        } else {
            if ($p->hourly_rate <= 0) {
                return response()->json(['message' => 'Indiquez le taux horaire du projet.'], 422);
            }
            $timesheets = Timesheet::where('project_id', $p->id)->where('billable', true)->whereNull('invoice_id')->whereNull('pack_id')
                ->when($d['until'] ?? null, fn ($q, $u) => $q->where('date', '<=', $u))->orderBy('date')->get();
            $gate = \App\Modules\Feuilles\FeuillesService::enabled() && app(\App\Modules\Feuilles\FeuillesService::class)->settings($company)['require_approval'];
            if ($gate && $timesheets->isNotEmpty()) {
                $fs = app(\App\Modules\Feuilles\FeuillesService::class);
                $approved = $fs->approvedWeeks($timesheets->pluck('user_id')->filter()->unique()->values()->all());
                $all = $timesheets->count();
                $timesheets = $timesheets->filter(fn ($t) => $fs->isApproved($approved, $t->user_id, $t->date->toDateString()))->values();
                if ($timesheets->isEmpty()) {
                    return response()->json(['message' => 'Aucun temps validé à facturer : '.$all.' saisie(s) attendent la validation des feuilles de temps.'], 422);
                }
            }
            if ($timesheets->isEmpty()) {
                return response()->json(['message' => 'Aucun temps facturable en attente.'], 422);
            }
            $tasks = Task::whereIn('id', $timesheets->pluck('task_id')->filter())->pluck('title', 'id');
            $users = User::where('company_id', $company->id)->pluck('name', 'id');
            $groupBy = $d['group_by'] ?? 'task';
            $groups = $groupBy === 'line' ? $timesheets->map(fn ($t) => collect([$t])) : $timesheets->groupBy(fn ($t) => $groupBy === 'user' ? $t->user_id : ($t->task_id ?? '—'));
            foreach ($groups as $key => $g) {
                $first = $g->first();
                $label = match ($groupBy) {
                    'user' => $users[$key] ?? 'Intervenant',
                    'line' => $first->date->format('d/m/Y').' — '.($first->description ?: ($tasks[$first->task_id] ?? 'Prestation')),
                    default => $key === '—' ? 'Prestations diverses' : ($tasks[$key] ?? 'Tâche'),
                };
                $lines[] = ['description' => $p->name.' — '.$label, 'quantity' => round($g->sum('hours'), 2), 'unit' => 'h', 'unit_price' => $p->hourly_rate, 'tax_rate' => $taxRate];
            }
        }
        $invoiceId = (string) Str::uuid();
        $res = $writer->upsert(SalesDocument::class, $invoiceId, [
            'type' => 'invoice', 'project_id' => $p->id, 'partner_id' => $p->partner_id, 'date' => now()->toDateString(), 'reference' => $p->code ?: Str::limit($p->name, 30, ''),
            'notes' => $milestone ? 'Jalon « '.$milestone->name.' »' : 'Temps passé du '.$timesheets->min('date')->format('d/m/Y').' au '.$timesheets->max('date')->format('d/m/Y').' ('.round($timesheets->sum('hours'), 2).' h)',
            'lines' => array_map(fn ($l) => $l + ['id' => (string) Str::uuid(), 'discount_percent' => 0], $lines),
        ] + (app('tenant')->hasModule('analytique') && class_exists(\App\Modules\Analytique\AnalyticService::class)
            ? ['analytic' => [app(\App\Modules\Analytique\AnalyticService::class)->projectSection($p)->id => 100]] : []));
        if ($res['status'] !== EntityWriter::APPLIED) {
            return response()->json(['message' => $res['message'] ?? 'Facture refusée.', 'errors' => $res['errors'] ?? null], 422);
        }
        foreach ($timesheets as $t) {
            $t->invoice_id = $invoiceId;
            $t->save();
        }
        if ($milestone) {
            $milestone->invoice_id = $invoiceId;
            $milestone->status = 'invoiced';
            $milestone->save();
        }
        ActivityLog::record('projets.invoiced', $p, ['invoice_id' => $invoiceId, 'lines' => count($lines)]);

        return ['invoice' => $res['record']->load('lines')];
    }

    /** Rentabilité : budget, main-d'œuvre, achats engagés / réalisés, CA, marge, projection. */
    public function costs(string $id, ProjectCostService $svc)
    {
        $p = Project::findOrFail($id);
        $c = $svc->compute($p);
        $svc->checkAlert($p, $c);

        return ['data' => $c];
    }

    public function costSettings(ProjectCostService $svc)
    {
        return ['data' => $svc->settings(app('tenant'))];
    }

    public function saveCostSettings(Request $r, ProjectCostService $svc)
    {
        $v = $r->validate(['hourly_cost' => 'nullable|numeric|min:0|max:100000000', 'user_costs' => 'nullable|array', 'user_costs.*' => 'nullable|numeric|min:0|max:100000000']);

        return ['data' => $svc->saveSettings(app('tenant'), $v)];
    }
}
