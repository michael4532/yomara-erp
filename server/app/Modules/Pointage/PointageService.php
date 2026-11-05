<?php

namespace App\Modules\Pointage;

use App\Models\Company;
use App\Modules\Paie\Models\PayrollRun;
use App\Modules\Paie\PayrollService;
use App\Modules\Pointage\Models\Badge;
use App\Modules\Pointage\Models\Punch;
use App\Modules\Pointage\Models\Schedule;
use App\Modules\Rh\Models\Employee;
use App\Modules\Rh\Models\Leave;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class PointageService
{
    /** Jours fériés fixes en Côte d'Ivoire (les fêtes religieuses mobiles s'ajoutent dans les réglages). */
    public const FIXED_HOLIDAYS = ['01-01' => 'Jour de l\'an', '05-01' => 'Fête du travail', '08-07' => 'Fête de l\'indépendance', '08-15' => 'Assomption', '11-01' => 'Toussaint', '11-15' => 'Journée nationale de la paix', '12-25' => 'Noël'];

    public const DEFAULTS = ['weekly_hours' => 40, 'holidays' => []];

    public function settings(Company $company): array
    {
        return array_merge(self::DEFAULTS, (array) data_get($company->settings, 'pointage', []));
    }

    /** Jours fériés de l'année : fixes + lundi de Pâques + ceux saisis (Tabaski, Ramadan, Maouloud, Ascension, Pentecôte…). */
    public function holidays(Company $company, int $year): array
    {
        $out = [];
        foreach (self::FIXED_HOLIDAYS as $md => $label) {
            $out["$year-$md"] = $label;
        }
        $easter = self::easter($year);
        $out[$easter->copy()->addDay()->toDateString()] = 'Lundi de Pâques';
        foreach ((array) $this->settings($company)['holidays'] as $h) {
            if (! empty($h['date']) && str_starts_with($h['date'], (string) $year)) {
                $out[$h['date']] = $h['label'] ?? 'Jour férié';
            }
        }
        ksort($out);

        return $out;
    }

    /** Dimanche de Pâques (algorithme grégorien, sans l'extension calendar). */
    public static function easter(int $y): Carbon
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($y, $month, $day)->startOfDay();
    }

    // ---------- Badges ----------

    /** Crée ou met à jour le badge d'une fiche salarié (nom, service, compte, statut). */
    public function syncBadge(Employee $e): void
    {
        $badge = Badge::withTrashed()->find($e->id) ?? new Badge;
        $badge->id = $e->id;
        $badge->company_id = $e->company_id;
        $badge->forceFill([
            'name' => $e->full_name, 'matricule' => $e->matricule, 'department' => $e->department, 'user_id' => $e->user_id,
            'active' => ($e->active ?? true) && ! $e->trashed() && (! $e->end_date || $e->end_date->gte(now()->startOfDay())),
        ]);
        if (! $badge->exists && ! $badge->schedule_id) {
            $badge->schedule_id = Schedule::where('company_id', $e->company_id)->where('is_default', true)->value('id');
        }
        if ($badge->isDirty() || ! $badge->exists || $badge->trashed()) {
            $badge->deleted_at = null;
            $badge->save();
        }
    }

    public function ensureSetup(Company $company): void
    {
        if (! Schedule::withTrashed()->exists()) {
            $day = ['start' => '08:00', 'end' => '17:00', 'break' => 60];
            $s = new Schedule(['name' => 'Journée 8 h – 17 h (lundi au vendredi)', 'days' => ['1' => $day, '2' => $day, '3' => $day, '4' => $day, '5' => $day, '6' => null, '7' => null], 'tolerance' => 10, 'is_default' => true]);
            $s->id = (string) Str::uuid();
            $s->save();
        }
        $known = Badge::withTrashed()->pluck('id')->flip();
        foreach (Employee::withTrashed()->get() as $e) {
            if (! isset($known[$e->id])) {
                $this->syncBadge($e);
            }
        }
    }

    // ---------- Calcul de la présence ----------

    /**
     * Présence jour par jour : premier / dernier pointage, minutes travaillées, retard, départ anticipé,
     * statut (present, late, absent, leave, holiday, rest, incomplete, upcoming).
     *
     * @return array<string, array{badge: Badge, days: array}>
     */
    public function days(Company $company, Collection $badges, string $from, string $to): array
    {
        $tz = $company->settings['timezone'] ?? config('app.timezone');
        $today = Carbon::now($tz)->toDateString();
        $schedules = Schedule::all()->keyBy('id');
        $default = $schedules->firstWhere('is_default', true);
        $ids = $badges->pluck('id');
        $punches = Punch::whereIn('employee_id', $ids)->whereBetween('date', [$from, $to])->orderBy('at')->get()->groupBy(fn ($p) => $p->employee_id.'|'.$p->date->toDateString());
        $leaves = Leave::whereIn('employee_id', $ids)->where('status', 'approved')->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $from)->get()->groupBy('employee_id');
        $holidays = [];
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            $holidays += $this->holidays($company, $y);
        }
        $hires = Employee::withTrashed()->whereIn('id', $ids)->get(['id', 'hire_date', 'end_date'])->keyBy('id');

        $out = [];
        foreach ($badges as $b) {
            $schedule = $schedules->get($b->schedule_id) ?? $default;
            $emp = $hires->get($b->id);
            $days = [];
            foreach (CarbonPeriod::create($from, $to) as $d) {
                $date = $d->toDateString();
                $plan = $schedule?->forDay($d->isoWeekday());
                $list = $punches->get($b->id.'|'.$date, collect());
                $leave = ($leaves->get($b->id) ?? collect())->first(fn ($l) => $l->start_date->toDateString() <= $date && $l->end_date->toDateString() >= $date);
                $row = $this->computeDay($date, $plan, $list, (int) ($schedule?->tolerance ?? 10), $date === $today);
                $row['holiday'] = $holidays[$date] ?? null;
                $row['leave'] = $leave ? ($leave->type) : null;
                $employed = (! $emp?->hire_date || $emp->hire_date->toDateString() <= $date) && (! $emp?->end_date || $emp->end_date->toDateString() >= $date);
                if ($list->isEmpty()) {
                    $row['status'] = match (true) {
                        ! $employed => 'off',
                        $leave !== null => 'leave',
                        $row['holiday'] !== null => 'holiday',
                        $plan === null => 'rest',
                        $date > $today => 'upcoming',
                        $date === $today && Carbon::now($tz)->format('H:i') < $plan['start'] => 'upcoming',
                        default => 'absent',
                    };
                }
                $days[$date] = $row;
            }
            $out[$b->id] = ['badge' => $b, 'schedule' => $schedule?->name, 'days' => $days];
        }

        return $out;
    }

    /** Une journée : paires arrivée / départ dans l'ordre, pause déduite si la journée n'a qu'une paire. */
    public function computeDay(string $date, ?array $plan, Collection $punches, int $tolerance, bool $isToday): array
    {
        $worked = 0;
        $night = 0;
        $open = null;
        $missing = false;
        foreach ($punches as $p) {
            if ($p->kind === 'in') {
                if ($open) {
                    $missing = true; // deux arrivées de suite : le départ intermédiaire manque
                }
                $open = $p->at;
            } elseif ($open) {
                $worked += $open->diffInMinutes($p->at);
                $night += self::nightMinutes($open, $p->at);
                $open = null;
            } else {
                $missing = true; // départ sans arrivée
            }
        }
        $first = $punches->firstWhere('kind', 'in')?->at;
        $last = $punches->where('kind', 'out')->last()?->at;
        $pairs = $punches->where('kind', 'out')->count();
        if ($plan && $plan['break'] > 0 && $pairs === 1 && $worked > $plan['break'] + 240) {
            $worked -= $plan['break'];
        }
        $late = 0;
        $early = 0;
        if ($plan && $first) {
            $start = Carbon::parse("$date {$plan['start']}");
            $diff = (int) $start->diffInMinutes($first, false);
            $late = $diff > $tolerance ? $diff : 0;
        }
        if ($plan && $last && ! $open) {
            $end = Carbon::parse("$date {$plan['end']}");
            $diff = (int) $last->diffInMinutes($end, false);
            $early = $diff > $tolerance ? $diff : 0;
        }
        $incomplete = $missing || ($open && ! $isToday);
        $status = $punches->isEmpty() ? null : ($incomplete ? 'incomplete' : ($open ? 'in' : ($late > 0 ? 'late' : 'present')));
        if ($open && $isToday && ! $missing) {
            $status = $late > 0 ? 'late' : 'in';
        }

        return [
            'date' => $date,
            'planned' => $plan ? $this->minutes($plan['start'], $plan['end']) - $plan['break'] : 0,
            'first_in' => $first?->format('H:i'),
            'last_out' => $last?->format('H:i'),
            'worked' => (int) round($worked),
            'night' => (int) round(min($night, $worked)),
            'late' => $late,
            'early' => $early,
            'open' => (bool) $open,
            'status' => $status,
            'punches' => $punches->map(fn ($p) => ['id' => $p->id, 'kind' => $p->kind, 'time' => $p->at->format('H:i'), 'source' => $p->source, 'late_sync' => $p->late_sync, 'note' => $p->note])->values()->all(),
        ];
    }

    /** Minutes travaillées de nuit (21 h – 5 h, Code du travail CI) entre deux pointages. */
    public static function nightMinutes(Carbon $from, Carbon $to): int
    {
        if ($to->lte($from)) {
            return 0;
        }
        $n = 0;
        $day = $from->copy()->subDay()->startOfDay();
        while ($day->lte($to)) {
            $a = $day->copy()->setTime(21, 0);
            $b = $day->copy()->addDay()->setTime(5, 0);
            $start = $from->gt($a) ? $from : $a;
            $end = $to->lt($b) ? $to : $b;
            if ($end->gt($start)) {
                $n += (int) $start->diffInMinutes($end);
            }
            $day->addDay();
        }

        return $n;
    }

    protected function minutes(string $a, string $b): int
    {
        [$h1, $m1] = array_map('intval', explode(':', $a));
        [$h2, $m2] = array_map('intval', explode(':', $b));
        $d = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);

        return $d < 0 ? $d + 1440 : $d; // horaire de nuit
    }

    /**
     * Totaux d'une période et heures supplémentaires par semaine civile (Code du travail CI, décret 96-203) :
     * au-delà de la durée hebdomadaire (40 h), les 6 premières heures de jour à +15 %, les suivantes à +50 %,
     * les heures supplémentaires de nuit (21 h – 5 h) à +75 % ; dimanche et jours fériés : +75 % le jour, +100 % la nuit.
     */
    public function totals(Company $company, array $days): array
    {
        $weekly = (float) $this->settings($company)['weekly_hours'] * 60;
        $t = ['worked' => 0, 'planned' => 0, 'late_minutes' => 0, 'late_count' => 0, 'early_minutes' => 0, 'absences' => 0, 'leave_days' => 0, 'incomplete' => 0, 'present_days' => 0,
            'night' => 0, 'ot15' => 0, 'ot50' => 0, 'ot75' => 0, 'ot_night' => 0, 'ot100' => 0];
        $weeks = [];
        foreach ($days as $d) {
            $night = (int) ($d['night'] ?? 0);
            $t['worked'] += $d['worked'];
            $t['night'] += $night;
            $t['late_minutes'] += $d['late'];
            $t['late_count'] += $d['late'] > 0 ? 1 : 0;
            $t['early_minutes'] += $d['early'];
            $t['absences'] += $d['status'] === 'absent' ? 1 : 0;
            $t['leave_days'] += $d['status'] === 'leave' ? 1 : 0;
            $t['incomplete'] += $d['status'] === 'incomplete' ? 1 : 0;
            $t['present_days'] += $d['worked'] > 0 ? 1 : 0;
            if (! in_array($d['status'], ['holiday', 'leave', 'off'], true)) {
                $t['planned'] += $d['planned'];
            }
            $c = Carbon::parse($d['date']);
            if ($c->isSunday() || $d['holiday']) {
                $t['ot100'] += $night;
                $t['ot75'] += $d['worked'] - $night;
            } else {
                $k = $c->format('o-W');
                $weeks[$k]['worked'] = ($weeks[$k]['worked'] ?? 0) + $d['worked'];
                $weeks[$k]['night'] = ($weeks[$k]['night'] ?? 0) + $night;
            }
        }
        foreach ($weeks as $w) {
            $over = max(0, $w['worked'] - $weekly);
            // Les heures au-delà de la durée légale sont d'abord celles de nuit (fin de journée prolongée)
            $nightOver = min($over, $w['night']);
            $dayOver = $over - $nightOver;
            $t['ot_night'] += $nightOver;
            $t['ot15'] += min($dayOver, 360);
            $t['ot50'] += max(0, $dayOver - 360);
        }

        return $t;
    }

    /** Majorations des heures supplémentaires (%), modifiables dans les réglages du pointage. */
    public const OVERTIME_RATES = ['ot15' => 15, 'ot50' => 50, 'ot_night' => 75, 'ot75' => 75, 'ot100' => 100];

    public const OVERTIME_LABELS = ['ot15' => 'de jour (6 premières)', 'ot50' => 'de jour (au-delà)', 'ot_night' => 'de nuit', 'ot75' => 'dimanche / férié', 'ot100' => 'nuit du dimanche / férié'];

    public function overtimeRates(Company $company): array
    {
        return array_replace(self::OVERTIME_RATES, array_map('floatval', (array) data_get($company->settings, 'pointage.overtime_rates', [])));
    }

    /**
     * Lignes « heures supplémentaires » du bulletin d'un salarié pour un mois (source = pointage).
     * Taux horaire = salaire de base ÷ heures mensuelles du contrat du salarié (durée hebdomadaire × 52 ÷ 12).
     */
    public function overtimeItems(Company $company, string $employeeId, string $period, float $baseSalary, ?float $weeklyHours = null): array
    {
        $badge = Badge::find($employeeId);
        if (! $badge) {
            return [[], null];
        }
        $from = Carbon::parse($period.'-01')->toDateString();
        $to = Carbon::parse($period.'-01')->endOfMonth()->toDateString();
        $t = $this->totals($company, $this->days($company, collect([$badge]), $from, $to)[$badge->id]['days'] ?? []);
        $weekly = $weeklyHours ?: (float) $this->settings($company)['weekly_hours'];
        $rate = $baseSalary / max(1, $weekly * 52 / 12);
        $items = [];
        foreach ($this->overtimeRates($company) as $k => $pct) {
            if (($t[$k] ?? 0) > 0) {
                $hours = round($t[$k] / 60, 2);
                $items[] = ['label' => 'Heures supplémentaires '.self::OVERTIME_LABELS[$k].' +'.rtrim(rtrim(number_format($pct, 2, ',', ''), '0'), ',').' % — '.str_replace('.', ',', (string) $hours).' h',
                    'amount' => round($rate * $hours * (1 + $pct / 100)), 'kind' => 'gain', 'taxable' => true, 'social' => true, 'source' => 'pointage', 'hours' => $hours, 'code' => $k];
            }
        }

        return [$items, $t];
    }

    /** Rapport d'une période pour une liste de badges. */
    public function report(Company $company, string $from, string $to, ?Collection $badges = null): array
    {
        $badges ??= Badge::where('active', true)->orWhereIn('id', Punch::whereBetween('date', [$from, $to])->select('employee_id'))->orderBy('name')->get();
        $rows = [];
        foreach ($this->days($company, $badges, $from, $to) as $id => $r) {
            $rows[] = ['employee_id' => $id, 'name' => $r['badge']->name, 'matricule' => $r['badge']->matricule, 'department' => $r['badge']->department,
                'schedule' => $r['schedule'], 'days' => array_values($r['days']), 'totals' => $this->totals($company, $r['days'])];
        }

        return ['from' => $from, 'to' => $to, 'weekly_hours' => $this->settings($company)['weekly_hours'], 'employees' => $rows];
    }

    // ---------- Paie ----------

    /**
     * Heures supplémentaires du mois ajoutées aux bulletins d'une paie en brouillon
     * (taux horaire = salaire de base ÷ heures mensuelles ; lignes imposables et soumises à CNPS).
     * Les lignes « pointage » précédentes sont remplacées : l'envoi peut être refait.
     */
    public function toPayroll(Company $company, PayrollRun $run): array
    {
        if ($run->status !== 'draft') {
            throw new \DomainException('Rouvrez la paie pour y envoyer les heures supplémentaires.');
        }
        $payroll = app(PayrollService::class);
        $done = [];
        foreach ($run->payslips()->get() as $slip) {
            $items = array_values(array_filter((array) $slip->items, fn ($i) => ($i['source'] ?? null) !== 'pointage'));
            [$ot, $t] = $this->overtimeItems($company, (string) $slip->employee_id, $run->period, (float) ($slip->employee['base_salary'] ?? 0), (float) ($slip->employee['weekly_hours'] ?? 0) ?: null);
            if ($t) {
                $items = array_merge($items, $ot);
                $done[] = ['employee_id' => $slip->employee_id, 'name' => $slip->employee['full_name'] ?? '', 'ot15' => $t['ot15'], 'ot50' => $t['ot50'], 'ot75' => $t['ot75'],
                    'ot_night' => $t['ot_night'], 'ot100' => $t['ot100'], 'absences' => $t['absences']];
            }
            $slip->items = $items;
            $payroll->computeSlip($company, $slip);
            $slip->save();
        }
        $payroll->updateTotals($run);

        return $done;
    }
}
