<?php

namespace App\Modules\Rh;

use App\Models\Company;
use App\Models\User;
use App\Modules\Rh\Models\Contract;
use App\Modules\Rh\Models\Department;
use App\Modules\Rh\Models\Employee;
use App\Modules\Rh\Models\EmployeeRequest;
use App\Modules\Rh\Models\Leave;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * « Mon équipe » : le chef de département voit ses collaborateurs et ses stagiaires, avec ce que la RH
 * lui a ouvert (présence, demandes, coordonnées, contrat, salaire, documents, travaux). La RH voit tout.
 */
class TeamService
{
    /** Droits du lecteur sur un salarié : null = aucun lien. */
    public function rightsOn(User $u, Employee $e): ?array
    {
        if ($u->hasPermission('rh.view|rh.manage')) {
            return array_fill_keys(array_keys(Department::VISIBILITY), true);
        }
        $me = Employee::where('user_id', $u->id)->value('id');
        if (! $me || $me === $e->id) {
            return null;
        }
        $rights = null;
        foreach (Department::ledBy($me) as $d) {
            if ($d->id === $e->department_id) {
                $rights = (array) $d->visibility;
            }
        }
        // Responsable direct sans département renseigné : présence et demandes
        if ($rights === null && ($e->manager_id === $me || RequestNotifier::managerOf($e)?->id === $me)) {
            $rights = ['presence' => true, 'requests' => true, 'work' => true];
        }

        return $rights === null ? null : array_merge(array_fill_keys(array_keys(Department::VISIBILITY), false), array_map('boolval', $rights));
    }

    public function team(Company $c, User $u, ?string $departmentId = null): array
    {
        $hr = $u->hasPermission('rh.view|rh.manage');
        $me = Employee::where('user_id', $u->id)->first();
        $depts = $me ? Department::ledBy($me->id) : collect();
        if ($hr && $departmentId) {
            $depts = Department::whereKey($departmentId)->get();
        }
        if ($departmentId) {
            $depts = $depts->where('id', $departmentId)->values();
        }
        $ids = $depts->pluck('id')->all();
        $members = Employee::where('active', true)->whereIn('department_id', $ids)->when($me, fn ($q) => $q->where('id', '!=', $me->id))->orderBy('last_name')->get();
        // Collaborateurs directs hors département (responsable direct)
        if ($me && ! $departmentId) {
            $direct = Employee::where('active', true)->where('manager_id', $me->id)->whereNotIn('id', $members->pluck('id'))->get();
            $members = $members->concat($direct);
        }

        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $leaves = Leave::whereIn('employee_id', $members->pluck('id'))->whereIn('status', ['approved', 'requested'])->whereDate('end_date', '>=', $today)->orderBy('start_date')->get()->groupBy('employee_id');
        $requests = EmployeeRequest::whereIn('employee_id', $members->pluck('id'))->whereIn('status', ['submitted', 'in_review'])->get()->groupBy('employee_id');
        $presence = $this->presence($c, $members, $monthStart, $today);
        $work = $this->work($c, $members, $monthStart);

        $rows = [];
        foreach ($members as $e) {
            $r = $this->rightsOn($u, $e) ?? ['presence' => true, 'requests' => true];
            $onLeave = ($leaves[$e->id] ?? collect())->first(fn ($l) => $l->status === 'approved' && $l->start_date->toDateString() <= $today);
            $row = [
                'id' => $e->id, 'name' => $e->full_name, 'job_title' => $e->job_title, 'department_id' => $e->department_id, 'department' => $e->department,
                'staff_type' => $e->staff_type ?? 'employee', 'staff_label' => Employee::STAFF_TYPES[$e->staff_type ?? 'employee'] ?? '', 'rights' => $r,
                'today' => $onLeave ? 'leave' : ($presence[$e->id]['today'] ?? null),
            ];
            if ($r['presence']) {
                $row['leaves'] = ($leaves[$e->id] ?? collect())->take(3)->map(fn ($l) => ['type' => Leave::TYPES[$l->type] ?? $l->type, 'start_date' => $l->start_date->toDateString(), 'end_date' => $l->end_date->toDateString(), 'status' => $l->status, 'days' => (float) $l->days])->values();
                $row['month'] = $presence[$e->id]['month'] ?? null;
            }
            if ($r['requests']) {
                $row['pending_requests'] = ($requests[$e->id] ?? collect())->count();
            }
            if ($r['personal']) {
                $row += ['phone' => $e->phone, 'email' => $e->email, 'birth_date' => $e->birth_date?->toDateString()];
            }
            if ($r['contract']) {
                $row += ['contract_type' => $e->contract_type, 'hire_date' => $e->hire_date?->toDateString(), 'end_date' => $e->end_date?->toDateString(), 'seniority_months' => $e->seniority_months,
                    'cnps_declared' => (bool) $e->cnps_declared, 'has_insurance' => (bool) $e->has_insurance, 'weekly_hours' => (float) $e->weekly_hours];
            }
            if ($r['salary']) {
                $row += ['base_salary' => (float) $e->base_salary, 'hourly_rate' => $e->hourly_rate];
            }
            if ($r['work']) {
                $row['work'] = $work[$e->id] ?? null;
            }
            $rows[] = $row;
        }

        $count = fn ($k) => collect($rows)->where('today', $k)->count();

        return [
            'me' => $me ? ['id' => $me->id, 'name' => $me->full_name] : null,
            'departments' => $depts->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'visibility' => (array) $d->visibility, 'parent_id' => $d->parent_id, 'color' => $d->color])->values(),
            'members' => $rows,
            'summary' => [
                'total' => count($rows),
                'employees' => collect($rows)->where('staff_type', 'employee')->count(),
                'interns' => collect($rows)->whereIn('staff_type', ['intern', 'apprentice'])->count(),
                'present' => $count('present'), 'late' => $count('late'), 'absent' => $count('absent'), 'leave' => $count('leave'),
                'pending_requests' => collect($rows)->sum(fn ($r) => $r['pending_requests'] ?? 0),
            ],
            'presence_tracked' => $c->hasModule('pointage'),
            'is_hr' => $hr,
        ];
    }

    /** Présence du jour et du mois (module Pointage). */
    protected function presence(Company $c, $members, string $from, string $to): array
    {
        if (! $c->hasModule('pointage') || ! class_exists(\App\Modules\Pointage\PointageService::class) || $members->isEmpty()) {
            return [];
        }
        try {
            $badges = \App\Modules\Pointage\Models\Badge::whereIn('id', $members->pluck('id'))->get();
            if ($badges->isEmpty()) {
                return [];
            }
            $out = [];
            foreach (app(\App\Modules\Pointage\PointageService::class)->report($c, $from, $to, $badges)['employees'] as $row) {
                $last = collect($row['days'])->last();
                $out[$row['employee_id']] = [
                    'today' => $last && $last['date'] === $to ? match ($last['status']) {
                        'present', 'in', 'incomplete' => 'present',
                        'late' => 'late',
                        'absent' => 'absent',
                        'leave' => 'leave',
                        default => $last['status'], // rest, holiday, upcoming, off
                    } : null,
                    'month' => ['worked_hours' => round($row['totals']['worked'] / 60, 1), 'present_days' => $row['totals']['present_days'], 'absences' => $row['totals']['absences'],
                        'late_count' => $row['totals']['late_count'], 'late_minutes' => $row['totals']['late_minutes']],
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** Travaux du mois : tâches de projet et temps passés (module Projets). */
    protected function work(Company $c, $members, string $from): array
    {
        if (! $c->hasModule('projets') || ! Schema::hasTable('prj_tasks')) {
            return [];
        }
        $users = $members->whereNotNull('user_id')->pluck('id', 'user_id');
        if ($users->isEmpty()) {
            return [];
        }
        $open = DB::table('prj_tasks')->where('company_id', $c->id)->whereNull('deleted_at')->whereIn('assignee_id', $users->keys())->where('status', '!=', 'done')
            ->selectRaw('assignee_id, count(*) n, sum(case when due_date < ? then 1 else 0 end) late', [now()->toDateString()])->groupBy('assignee_id')->get()->keyBy('assignee_id');
        $done = DB::table('prj_tasks')->where('company_id', $c->id)->whereNull('deleted_at')->whereIn('assignee_id', $users->keys())->where('status', 'done')->where('done_at', '>=', $from)
            ->selectRaw('assignee_id, count(*) n')->groupBy('assignee_id')->pluck('n', 'assignee_id');
        $hours = Schema::hasTable('prj_timesheets') ? DB::table('prj_timesheets')->where('company_id', $c->id)->whereNull('deleted_at')->whereIn('user_id', $users->keys())->where('date', '>=', $from)
            ->selectRaw('user_id, sum(hours) h')->groupBy('user_id')->pluck('h', 'user_id') : collect();
        $out = [];
        foreach ($users as $userId => $empId) {
            $out[$empId] = ['open_tasks' => (int) ($open[$userId]->n ?? 0), 'late_tasks' => (int) ($open[$userId]->late ?? 0), 'done_this_month' => (int) ($done[$userId] ?? 0), 'hours_this_month' => round((float) ($hours[$userId] ?? 0), 1)];
        }

        return $out;
    }

    /** Fiche d'un collaborateur vue par son chef (filtrée selon les droits ouverts par la RH). */
    public function member(Company $c, User $u, Employee $e): array
    {
        $r = $this->rightsOn($u, $e);
        abort_unless($r, 403, 'Ce salarié ne fait pas partie de votre équipe.');
        $out = ['id' => $e->id, 'name' => $e->full_name, 'job_title' => $e->job_title, 'department' => $e->department, 'staff_type' => $e->staff_type, 'staff_label' => Employee::STAFF_TYPES[$e->staff_type ?? 'employee'] ?? '', 'matricule' => $e->matricule, 'rights' => $r];
        if ($r['personal']) {
            $out += ['phone' => $e->phone, 'email' => $e->email, 'address' => $e->address, 'birth_date' => $e->birth_date?->toDateString(), 'emergency_contact' => $e->emergency_contact, 'id_number' => $e->id_number];
        }
        if ($r['contract']) {
            $out += ['contract_type' => $e->contract_type, 'hire_date' => $e->hire_date?->toDateString(), 'end_date' => $e->end_date?->toDateString(), 'seniority_months' => $e->seniority_months,
                'cnps_declared' => (bool) $e->cnps_declared, 'cmu_enrolled' => (bool) $e->cmu_enrolled, 'has_insurance' => (bool) $e->has_insurance, 'insurance_name' => $e->insurance_name,
                'weekly_hours' => (float) $e->weekly_hours, 'work_schedule' => $e->work_schedule,
                'contracts' => Contract::where('employee_id', $e->id)->orderByDesc('start_date')->get(array_merge(['id', 'number', 'type', 'start_date', 'end_date', 'trial_end_date', 'status', 'weekly_hours'], $r['salary'] ? ['salary'] : []))];
        }
        if ($r['salary']) {
            $out += ['base_salary' => (float) $e->base_salary, 'transport_allowance' => (float) $e->transport_allowance, 'hourly_rate' => $e->hourly_rate];
            if ($c->hasModule('paie') && Schema::hasTable('paie_payslips')) {
                $out['payslips'] = DB::table('paie_payslips as p')->join('paie_runs as r', 'r.id', '=', 'p.run_id')->where('p.employee_id', $e->id)->whereIn('r.status', ['validated', 'paid'])
                    ->orderByDesc('r.period')->limit(12)->get(['r.period', 'r.status', 'p.gross', 'p.net']);
            }
        }
        if ($r['presence']) {
            $out['leaves'] = Leave::where('employee_id', $e->id)->orderByDesc('start_date')->limit(30)->get(['id', 'type', 'start_date', 'end_date', 'days', 'status', 'reason'])
                ->map(fn ($l) => $l->toArray() + ['type_label' => Leave::TYPES[$l->type] ?? $l->type]);
            $out['presence'] = $this->presence($c, collect([$e]), now()->startOfMonth()->toDateString(), now()->toDateString())[$e->id] ?? null;
        }
        if ($r['requests']) {
            $out['requests'] = EmployeeRequest::where('employee_id', $e->id)->orderByDesc('created_at')->limit(30)->get(['id', 'number', 'type', 'subject', 'status', 'created_at', 'attachments'])
                ->map(fn ($x) => $x->toArray() + ['type_label' => EmployeeRequest::TYPES[$x->type] ?? $x->type] + ($r['documents'] ? [] : ['attachments' => []]));
        }
        if ($r['documents']) {
            $out['documents'] = DB::table('rh_documents')->where('company_id', $c->id)->where('employee_id', $e->id)->whereNull('deleted_at')->orderByDesc('date')->get(['id', 'type', 'title', 'number', 'date', 'status']);
        }
        if ($r['work']) {
            $out['work'] = $this->work($c, collect([$e]), now()->startOfMonth()->toDateString())[$e->id] ?? null;
            if ($e->user_id && $c->hasModule('projets') && Schema::hasTable('prj_tasks')) {
                $out['tasks'] = DB::table('prj_tasks as t')->leftJoin('prj_projects as p', 'p.id', '=', 't.project_id')->where('t.company_id', $c->id)->whereNull('t.deleted_at')
                    ->where('t.assignee_id', $e->user_id)->where(fn ($q) => $q->where('t.status', '!=', 'done')->orWhere('t.done_at', '>=', now()->subDays(30)))
                    ->orderBy('t.status')->orderBy('t.due_date')->limit(30)->get(['t.id', 't.title', 't.status', 't.due_date', 't.done_at', 'p.name as project']);
            }
        }

        return $out;
    }
}
