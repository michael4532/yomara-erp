<?php

namespace App\Modules\Rh;

use App\Models\Company;
use App\Modules\Rh\Models\Employee;
use App\Modules\Rh\Models\Leave;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Solde de tout compte (Code du travail ivoirien et convention collective interprofessionnelle) :
 *  - indemnité compensatrice de congés payés : jours acquis non pris × salaire moyen ÷ 26 jours ouvrables ;
 *  - indemnité compensatrice de préavis si le préavis n'est pas effectué à la demande de l'employeur ;
 *  - indemnité de licenciement (licenciement hors faute lourde, départ à la retraite) : par année d'ancienneté,
 *    30 % du salaire moyen mensuel jusqu'à 5 ans, 35 % de la 6e à la 10e année, 40 % au-delà ;
 *  - indemnité de fin de CDD : 3 % des salaires bruts perçus pendant le contrat.
 * Tous les paramètres sont modifiables (réglage paie.settlement) et chaque ligne reste corrigeable avant validation.
 */
class SettlementService
{
    public const REASONS = [
        'resignation' => 'Démission', 'dismissal' => 'Licenciement', 'economic' => 'Licenciement pour motif économique', 'misconduct' => 'Licenciement pour faute lourde',
        'end_cdd' => 'Fin de CDD', 'end_trial' => 'Fin de période d\'essai', 'retirement' => 'Départ à la retraite', 'mutual' => 'Rupture d\'un commun accord', 'death' => 'Décès',
    ];

    public const DEFAULTS = [
        'leave_divisor' => 26,
        'severance' => [['up_to_years' => 5, 'rate' => 30], ['up_to_years' => 10, 'rate' => 35], ['up_to_years' => null, 'rate' => 40]],
        'cdd_rate' => 3,
        // Préavis (mois) par groupe de catégorie
        'notice' => ['employe' => 1, 'maitrise' => 2, 'cadre' => 3],
    ];

    public function settings(Company $c): array
    {
        return array_replace(self::DEFAULTS, (array) data_get($c->settings, 'paie.settlement', []));
    }

    /** Salaire brut moyen mensuel des 12 derniers mois payés (à défaut : salaire de base et primes fixes). */
    public function averageGross(Employee $e, Carbon $end): array
    {
        $rows = DB::table('paie_payslips')->join('paie_runs', 'paie_runs.id', '=', 'paie_payslips.run_id')
            ->where('paie_payslips.employee_id', $e->id)->whereIn('paie_runs.status', ['validated', 'paid'])->where('paie_runs.period', '<=', $end->format('Y-m'))
            ->orderByDesc('paie_runs.period')->limit(12)->pluck('paie_payslips.gross')->map(fn ($v) => (float) $v)->all();
        if ($rows) {
            return [round(array_sum($rows) / count($rows)), count($rows)];
        }
        $fixed = array_sum(array_map(fn ($i) => (float) ($i['amount'] ?? 0), (array) $e->fixed_items));

        return [round((float) $e->base_salary + $fixed), 0];
    }

    /** Groupe de préavis d'après la catégorie (grille interprofessionnelle) ou le poste. */
    public function noticeGroup(Employee $e): string
    {
        $g = mb_strtolower((string) DB::table('rh_categories')->where('company_id', $e->company_id)->where('code', $e->category)->value('group'));
        $cat = strtoupper((string) $e->category);
        if (str_contains($g, 'cadre') || str_starts_with($cat, 'P')) {
            return 'cadre';
        }
        if (str_contains($g, 'ma') && str_contains($g, 'trise') || str_starts_with($cat, 'M')) {
            return 'maitrise';
        }

        return 'employe';
    }

    /** Jours de congés acquis non pris à la date de sortie (année en cours). */
    public function leaveDays(Employee $e, Carbon $end): float
    {
        $yearStart = $end->copy()->startOfYear();
        $from = $e->hire_date && $e->hire_date->gt($yearStart) ? $e->hire_date : $yearStart;
        $months = max(0, $from->diffInMonths($end->copy()->addDay()));
        $taken = (float) Leave::where('employee_id', $e->id)->where('status', 'approved')->where('type', 'annual')
            ->whereDate('start_date', '>=', $yearStart->toDateString())->whereDate('start_date', '<=', $end->toDateString())->sum('days');

        return max(0, round((float) $e->leave_opening_balance + floor($months * 10) / 10 * (float) ($e->leave_days_per_month ?: 2.2) - $taken, 1));
    }

    /**
     * Calcul détaillé. $in : end_date, reason, notice_worked (bool), notice_months, leave_days, average (forçages facultatifs).
     */
    public function compute(Company $c, Employee $e, array $in): array
    {
        $s = $this->settings($c);
        $end = Carbon::parse($in['end_date'] ?? now()->toDateString())->startOfDay();
        $reason = $in['reason'] ?? 'resignation';
        $hire = $e->hire_date ?? $end;
        $months = max(0, $hire->diffInMonths($end->copy()->addDay()));
        $years = $months / 12;
        [$avgAuto, $sample] = $this->averageGross($e, $end);
        $avg = isset($in['average']) && $in['average'] !== null ? (float) $in['average'] : $avgAuto;
        $lines = [];
        $add = function (string $code, string $label, float $amount, string $detail, bool $taxable, bool $social) use (&$lines) {
            if (round($amount) > 0) {
                $lines[] = ['code' => $code, 'label' => $label, 'amount' => round($amount), 'detail' => $detail, 'taxable' => $taxable, 'social' => $social];
            }
        };

        // Congés payés acquis non pris
        $leave = isset($in['leave_days']) && $in['leave_days'] !== null ? (float) $in['leave_days'] : $this->leaveDays($e, $end);
        $add('conges', 'Indemnité compensatrice de congés payés', $avg / (float) $s['leave_divisor'] * $leave,
            str_replace('.', ',', (string) $leave).' j × '.number_format($avg, 0, ',', ' ').' ÷ '.$s['leave_divisor'], true, true);

        // Préavis non effectué (à l'initiative de l'employeur) — pas dû en cas de faute lourde, de fin de CDD ou d'essai
        $group = $this->noticeGroup($e);
        $noticeMonths = isset($in['notice_months']) && $in['notice_months'] !== null ? (float) $in['notice_months'] : (float) ($s['notice'][$group] ?? 1);
        $noticeDue = in_array($reason, ['dismissal', 'economic', 'retirement'], true) && empty($in['notice_worked']);
        if ($noticeDue) {
            $add('preavis', 'Indemnité compensatrice de préavis', $avg * $noticeMonths, str_replace('.', ',', (string) $noticeMonths).' mois × '.number_format($avg, 0, ',', ' '), true, true);
        }

        // Indemnité de licenciement / de départ à la retraite
        if (in_array($reason, ['dismissal', 'economic', 'retirement'], true) && $years >= 1) {
            $amount = 0.0;
            $parts = [];
            $floor = 0.0;
            foreach ($s['severance'] as $band) {
                $cap = $band['up_to_years'] === null ? INF : (float) $band['up_to_years'];
                $y = max(0, min($years, $cap) - $floor);
                if ($y > 0) {
                    $amount += $avg * (float) $band['rate'] / 100 * $y;
                    $parts[] = number_format($y, 2, ',', '').' an(s) × '.$band['rate'].' %';
                }
                $floor = $cap;
                if ($years <= $cap) {
                    break;
                }
            }
            $add('licenciement', $reason === 'retirement' ? 'Indemnité de départ à la retraite' : 'Indemnité de licenciement', $amount, implode(' + ', $parts).' × '.number_format($avg, 0, ',', ' '), false, false);
        }

        // Fin de CDD : indemnité de fin de contrat
        if ($reason === 'end_cdd') {
            $paid = (float) DB::table('paie_payslips')->join('paie_runs', 'paie_runs.id', '=', 'paie_payslips.run_id')->where('paie_payslips.employee_id', $e->id)
                ->whereIn('paie_runs.status', ['validated', 'paid'])->where('paie_runs.period', '>=', $hire->format('Y-m'))->sum('paie_payslips.gross');
            $paid = $paid ?: $avg * max(1, $months);
            $add('fin_cdd', 'Indemnité de fin de contrat (CDD)', $paid * (float) $s['cdd_rate'] / 100, $s['cdd_rate'].' % × '.number_format($paid, 0, ',', ' ').' de salaires', true, true);
        }

        return [
            'employee_id' => $e->id, 'name' => $e->full_name, 'end_date' => $end->toDateString(), 'period' => $end->format('Y-m'),
            'reason' => $reason, 'reason_label' => self::REASONS[$reason] ?? $reason,
            'seniority_months' => $months, 'seniority_label' => intdiv($months, 12).' an(s) '.($months % 12).' mois',
            'average_gross' => $avg, 'average_sample' => $sample, 'leave_days' => $leave, 'notice_group' => $group, 'notice_months' => $noticeMonths, 'notice_due' => $noticeDue,
            'lines' => $lines, 'total' => array_sum(array_column($lines, 'amount')),
            'note' => 'Montants bruts : le salaire du dernier mois, les cotisations et l\'impôt sont calculés par la paie du mois de sortie.',
        ];
    }

    /**
     * Valide le solde : date et motif de sortie sur la fiche, lignes reprises automatiquement dans la paie du mois de sortie.
     */
    public function apply(Company $c, Employee $e, array $in, array $lines): array
    {
        $r = $this->compute($c, $e, $in);
        $r['lines'] = array_values(array_filter(array_map(fn ($l) => [
            'code' => (string) ($l['code'] ?? 'autre'), 'label' => (string) $l['label'], 'amount' => round((float) $l['amount']), 'detail' => $l['detail'] ?? null,
            'taxable' => (bool) ($l['taxable'] ?? true), 'social' => (bool) ($l['social'] ?? true),
        ], $lines ?: $r['lines']), fn ($l) => $l['amount'] > 0));
        $r['total'] = array_sum(array_column($r['lines'], 'amount'));
        $r['validated_at'] = now()->toIso8601String();
        $r['validated_by'] = auth()->user()?->name;
        $e->forceFill(['end_date' => $r['end_date'], 'settlement' => $r])->save();

        // Paie du mois de sortie déjà en brouillon : recalculée tout de suite
        $run = \App\Modules\Paie\Models\PayrollRun::where('period', $r['period'])->where('status', 'draft')->first();
        if ($run && class_exists(\App\Modules\Paie\PayrollService::class)) {
            app(\App\Modules\Paie\PayrollService::class)->refresh($c, $run);
        }

        return $r;
    }

    /** Lignes de bulletin du solde de tout compte pour la période (source « stc »). */
    public static function payslipItems(Employee $e, string $period): array
    {
        $s = (array) $e->settlement;
        if (! $s || ($s['period'] ?? null) !== $period) {
            return [];
        }

        return array_map(fn ($l) => ['label' => $l['label'], 'amount' => (float) $l['amount'], 'kind' => 'gain', 'taxable' => (bool) $l['taxable'], 'social' => (bool) $l['social'], 'source' => 'stc', 'code' => $l['code']], (array) ($s['lines'] ?? []));
    }
}
