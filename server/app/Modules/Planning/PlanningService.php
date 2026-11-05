<?php

namespace App\Modules\Planning;

use App\Models\Company;
use App\Models\User;
use App\Modules\Planning\Models\Assignment;
use Carbon\Carbon;
use Carbon\CarbonPeriod;

/**
 * Capacité, charge planifiée et temps réalisé de l'équipe.
 * Capacité d'un jour : 0 si férié ou congé approuvé, sinon l'horaire de pointage du salarié, sinon la semaine type (réglages).
 */
class PlanningService
{
    public const DEFAULTS = ['week_hours' => 40, 'work_days' => [1, 2, 3, 4, 5], 'overload_percent' => 100];

    public function settings(Company $c): array
    {
        $s = array_merge(self::DEFAULTS, (array) data_get($c->settings, 'planning', []));
        $s['work_days'] = array_values(array_map('intval', (array) $s['work_days']));

        return $s;
    }

    public function saveSettings(Company $c, array $v): array
    {
        $s = (array) ($c->settings ?? []);
        $s['planning'] = array_merge($this->settings($c), $v);
        $c->settings = $s;
        $c->save();

        return $this->settings($c);
    }

    /** @return array<int, array<string, array{cap: float, why: ?string}>> capacité par personne et par jour */
    public function capacity(Company $company, $users, string $from, string $to): array
    {
        $s = $this->settings($company);
        $perDay = $s['work_days'] ? round($s['week_hours'] / count($s['work_days']), 2) : 0;
        $holidays = [];
        if (class_exists(\App\Modules\Pointage\PointageService::class)) {
            $ps = app(\App\Modules\Pointage\PointageService::class);
            for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
                $holidays += $ps->holidays($company, $y);
            }
        }
        $employees = $leaves = $schedules = $badges = collect();
        if ($company->hasModule('rh')) {
            $employees = \App\Modules\Rh\Models\Employee::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');
            $leaves = \App\Modules\Rh\Models\Leave::whereIn('employee_id', $employees->pluck('id'))->where('status', 'approved')
                ->where('start_date', '<=', $to)->where('end_date', '>=', $from)->get()->groupBy('employee_id');
        }
        if ($company->hasModule('pointage') && $employees->isNotEmpty()) {
            $badges = \App\Modules\Pointage\Models\Badge::whereIn('id', $employees->pluck('id'))->get()->keyBy('id');
            $schedules = \App\Modules\Pointage\Models\Schedule::all()->keyBy('id');
        }
        $default = $schedules->firstWhere('is_default', true);
        $out = [];
        foreach ($users as $u) {
            $emp = $employees->get($u->id);
            $badge = $emp ? $badges->get($emp->id) : null;
            $sched = $badge ? ($schedules->get($badge->schedule_id) ?? $default) : null;
            foreach (CarbonPeriod::create($from, $to) as $day) {
                $d = $day->toDateString();
                $why = null;
                if (isset($holidays[$d])) {
                    $cap = 0;
                    $why = $holidays[$d];
                } elseif ($emp && ($leaves->get($emp->id) ?? collect())->first(fn ($l) => $l->start_date->toDateString() <= $d && $l->end_date->toDateString() >= $d)) {
                    $cap = 0;
                    $why = 'Congé';
                } elseif ($sched) {
                    $h = $sched->forDay($day->isoWeekday());
                    $cap = $h ? max(0, round((strtotime($h['end']) - strtotime($h['start'])) / 3600 - $h['break'] / 60, 2)) : 0;
                } else {
                    $cap = in_array($day->isoWeekday(), $s['work_days'], true) ? $perDay : 0;
                }
                $out[$u->id][$d] = ['cap' => (float) $cap, 'why' => $why];
            }
        }

        return $out;
    }

    /** Heures réellement saisies (projets et forfaits) par personne et par jour. */
    public function actual(Company $company, array $userIds, string $from, string $to): array
    {
        $out = [];
        $add = function ($rows) use (&$out) {
            foreach ($rows as $r) {
                $d = Carbon::parse($r->date)->toDateString();
                $out[$r->user_id][$d] = round(($out[$r->user_id][$d] ?? 0) + (float) $r->hours, 2);
            }
        };
        if ($company->hasModule('projets')) {
            $add(\App\Modules\Projets\Models\Timesheet::whereIn('user_id', $userIds)->whereBetween('date', [$from, $to])->get(['user_id', 'date', 'hours']));
        }
        if ($company->hasModule('prestations')) {
            $add(\App\Modules\Prestations\Models\Entry::whereIn('user_id', $userIds)->whereNull('timesheet_id')->whereBetween('date', [$from, $to])->get(['user_id', 'date', 'hours']));
        }

        return $out;
    }

    /** Charge de l'équipe sur la période : capacité, prévu, réalisé, occupation, surcharges. */
    public function load(Company $company, string $from, string $to): array
    {
        $assignments = Assignment::where('start_date', '<=', $to)->where('end_date', '>=', $from)->get();
        // L'équipe planifiable : les personnes ayant accès au planning, et toute personne déjà affectée
        $assigned = $assignments->pluck('user_id')->unique()->all();
        $users = User::where('company_id', $company->id)->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (User $u) => in_array($u->id, $assigned, true) || $u->hasPermission('planning.view') || $u->hasPermission('planning.manage'))->values();
        $cap = $this->capacity($company, $users, $from, $to);
        $act = $this->actual($company, $users->pluck('id')->all(), $from, $to);
        $limit = $this->settings($company)['overload_percent'] / 100;
        $rows = [];
        $team = ['capacity' => 0, 'planned' => 0, 'actual' => 0];
        foreach ($users as $u) {
            $days = [];
            foreach ($cap[$u->id] as $d => $c) {
                $planned = 0;
                $items = [];
                foreach ($assignments->where('user_id', $u->id) as $a) {
                    if ($a->start_date->toDateString() <= $d && $a->end_date->toDateString() >= $d && $c['cap'] > 0) {
                        $planned += min($a->hours_per_day, $c['cap']);
                        $items[] = $a->id;
                    }
                }
                $conflict = $c['cap'] == 0 && $assignments->where('user_id', $u->id)
                    ->first(fn ($a) => $a->start_date->toDateString() <= $d && $a->end_date->toDateString() >= $d && $c['why'] === 'Congé');
                $days[$d] = ['capacity' => $c['cap'], 'planned' => round($planned, 2), 'actual' => $act[$u->id][$d] ?? 0, 'off' => $c['why'],
                    'overload' => $c['cap'] > 0 && $planned > $c['cap'] * $limit + 0.001, 'leave_conflict' => (bool) $conflict];
            }
            $capT = array_sum(array_column($days, 'capacity'));
            $plT = array_sum(array_column($days, 'planned'));
            $acT = array_sum(array_column($days, 'actual'));
            $team['capacity'] += $capT;
            $team['planned'] += $plT;
            $team['actual'] += $acT;
            $rows[] = ['user_id' => $u->id, 'name' => $u->name, 'capacity' => round($capT, 2), 'planned' => round($plT, 2), 'actual' => round($acT, 2),
                'occupation' => $capT > 0 ? round(100 * $plT / $capT) : null, 'available' => round(max(0, $capT - $plT), 2),
                'overload_days' => count(array_filter($days, fn ($x) => $x['overload'])), 'leave_conflicts' => count(array_filter($days, fn ($x) => $x['leave_conflict'])), 'days' => $days];
        }

        return ['from' => $from, 'to' => $to, 'users' => $rows, 'team' => [
            'capacity' => round($team['capacity'], 2), 'planned' => round($team['planned'], 2), 'actual' => round($team['actual'], 2),
            'occupation' => $team['capacity'] > 0 ? round(100 * $team['planned'] / $team['capacity']) : null,
        ]];
    }

    /** Personnes disponibles sur une période (au moins $hours heures libres par jour ouvré). */
    public function available(Company $company, string $from, string $to, float $hours = 4): array
    {
        $l = $this->load($company, $from, $to);

        return collect($l['users'])->filter(function ($u) use ($hours) {
            $work = array_filter($u['days'], fn ($d) => $d['capacity'] > 0);

            return $work && collect($work)->every(fn ($d) => $d['capacity'] - $d['planned'] >= $hours - 0.001);
        })->map(fn ($u) => ['user_id' => $u['user_id'], 'name' => $u['name'], 'available' => $u['available']])->values()->all();
    }
}
