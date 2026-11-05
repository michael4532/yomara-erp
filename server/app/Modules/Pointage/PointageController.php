<?php

namespace App\Modules\Pointage;

use App\Http\Controllers\Controller;
use App\Modules\ImportExport\Sheet;
use App\Modules\Paie\Models\PayrollRun;
use App\Modules\Pointage\Models\Badge;
use Carbon\Carbon;
use Illuminate\Http\Request;

class PointageController extends Controller
{
    public function __construct(protected PointageService $svc) {}

    protected function period(Request $r): array
    {
        $v = $r->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        if (Carbon::parse($v['from'])->diffInDays(Carbon::parse($v['to'])) > 92) {
            abort(422, 'Période limitée à trois mois.');
        }

        return [Carbon::parse($v['from'])->toDateString(), Carbon::parse($v['to'])->toDateString()];
    }

    public function report(Request $r)
    {
        [$from, $to] = $this->period($r);
        $badges = $r->query('employee_id') ? Badge::where('id', $r->query('employee_id'))->get() : null;

        return ['data' => $this->svc->report(app('tenant'), $from, $to, $badges)];
    }

    /** Feuille de présence Excel : une ligne par salarié et par jour, puis les totaux. */
    public function export(Request $r)
    {
        [$from, $to] = $this->period($r);
        $rep = $this->svc->report(app('tenant'), $from, $to);
        $h = fn (int $m) => $m ? sprintf('%d:%02d', intdiv($m, 60), $m % 60) : '';
        $status = ['present' => 'Présent', 'late' => 'Retard', 'in' => 'Sur place', 'absent' => 'Absent', 'leave' => 'Congé', 'holiday' => 'Férié', 'rest' => 'Repos', 'incomplete' => 'Pointage incomplet', 'upcoming' => '', 'off' => ''];
        $rows = [];
        foreach ($rep['employees'] as $e) {
            foreach ($e['days'] as $d) {
                if (in_array($d['status'], ['upcoming', 'off'], true)) {
                    continue;
                }
                $rows[] = [$e['matricule'], $e['name'], Carbon::parse($d['date'])->format('d/m/Y'), $status[$d['status']] ?? $d['status'], $d['first_in'], $d['last_out'], $h($d['worked']), $d['late'] ?: '', $d['early'] ?: '', $d['holiday'] ?? ''];
            }
        }
        $rows[] = [];
        $rows[] = ['TOTAUX', '', '', 'Jours présents', 'Absences', 'Retards', 'Heures travaillées', 'HS 15 %', 'HS 50 %', 'HS 75 %'];
        foreach ($rep['employees'] as $e) {
            $t = $e['totals'];
            $rows[] = [$e['matricule'], $e['name'], '', $t['present_days'], $t['absences'], $t['late_count'].' ('.$t['late_minutes'].' min)', $h($t['worked']), $h($t['ot15']), $h($t['ot50']), $h($t['ot75'])];
        }
        $path = tempnam(sys_get_temp_dir(), 'pt').'.xlsx';
        Sheet::write($path, ['Matricule', 'Nom', 'Date', 'Statut', 'Arrivée', 'Départ', 'Travaillé', 'Retard (min)', 'Départ anticipé (min)', 'Jour férié'], $rows);

        return response()->download($path, 'presence_'.$from.'_'.$to.'.xlsx')->deleteFileAfterSend();
    }

    public function settings()
    {
        $c = app('tenant');

        return ['data' => ['overtime_rates' => $this->svc->overtimeRates($c), 'overtime_labels' => PointageService::OVERTIME_LABELS,
            'auto_overtime' => data_get($c->settings, 'paie.auto_overtime', true) !== false] + $this->svc->settings($c) + ['year_holidays' => $this->svc->holidays($c, (int) now()->year)]];
    }

    public function updateSettings(Request $r)
    {
        $v = $r->validate(['weekly_hours' => 'sometimes|numeric|min:20|max:60', 'holidays' => 'sometimes|array|max:40', 'holidays.*.date' => 'required|date', 'holidays.*.label' => 'nullable|string|max:80',
            'overtime_rates' => 'sometimes|array', 'overtime_rates.*' => 'numeric|min:0|max:300', 'auto_overtime' => 'sometimes|boolean']);
        $c = app('tenant');
        $settings = $c->settings ?? [];
        if (array_key_exists('auto_overtime', $v)) {
            $settings['paie']['auto_overtime'] = (bool) $v['auto_overtime'];
            unset($v['auto_overtime']);
        }
        if (isset($v['overtime_rates'])) {
            $v['overtime_rates'] = array_intersect_key($v['overtime_rates'], PointageService::OVERTIME_RATES);
        }
        $settings['pointage'] = array_merge($this->svc->settings($c), $v);
        $c->forceFill(['settings' => $settings])->save();

        return $this->settings();
    }

    protected function setPin(Badge $b, ?string $pin): void
    {
        if ($pin !== null && ! preg_match('/^\d{4,6}$/', $pin)) {
            abort(422, 'Le code PIN compte 4 à 6 chiffres.');
        }
        $b->forceFill(['pin_hash' => $pin === null ? null : Badge::hashPin($b->id, $pin)])->save();
    }

    public function pin(Request $r, string $id)
    {
        $b = Badge::findOrFail($id);
        $this->setPin($b, $r->input('pin'));

        return ['data' => $b->fresh()];
    }

    protected function myBadge(Request $r): Badge
    {
        $b = Badge::where('user_id', $r->user()->id)->first();
        abort_unless($b, 404, 'Votre compte n\'est relié à aucune fiche salarié.');

        return $b;
    }

    public function myPin(Request $r)
    {
        $r->validate(['pin' => 'required|digits_between:4,6']);
        $this->setPin($this->myBadge($r), (string) $r->input('pin'));

        return ['ok' => true];
    }

    public function me(Request $r)
    {
        [$from, $to] = $this->period($r);
        $b = $this->myBadge($r);

        return ['data' => $this->svc->report(app('tenant'), $from, $to, collect([$b]))['employees'][0] ?? null];
    }

    public function payroll(Request $r)
    {
        $run = PayrollRun::findOrFail($r->validate(['run_id' => 'required|integer'])['run_id']);
        try {
            return ['data' => $this->svc->toPayroll(app('tenant'), $run)];
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
