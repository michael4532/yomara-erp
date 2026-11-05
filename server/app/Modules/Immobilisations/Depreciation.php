<?php

namespace App\Modules\Immobilisations;

use Illuminate\Support\Carbon;

/**
 * Plans d'amortissement (SYSCOHADA) :
 *  - linéaire : prorata temporis au jour près en année de 360 jours, à partir de la mise en service ;
 *  - dégressif : à partir du 1er jour du mois d'acquisition, taux = coefficient / durée
 *    (1,5 pour 3-4 ans, 2 pour 5-6 ans, 2,5 au-delà), bascule en linéaire dès que
 *    l'annuité linéaire sur la durée restante devient supérieure.
 * Exercice civil (31 décembre). Périodicité annuelle ou mensuelle.
 */
class Depreciation
{
    public static function defaultCoefficient(int $years): float
    {
        return match (true) {
            $years <= 2 => 1.0,
            $years <= 4 => 1.5,
            $years <= 6 => 2.0,
            default => 2.5,
        };
    }

    /** Jours entre a (inclus) et b (inclus), base 30/360 européenne. */
    public static function days360(Carbon $a, Carbon $b): int
    {
        if ($b->lt($a)) {
            return 0;
        }
        $e = $b->copy()->addDay();
        $d1 = min($a->day, 30);
        if ($a->isLastOfMonth() && $a->month === 2) {
            $d1 = 30;
        }
        $d2 = min($e->day, 30);

        return ($e->year - $a->year) * 360 + ($e->month - $a->month) * 30 + ($d2 - $d1);
    }

    /**
     * @param  array{value: float, salvage?: float, method: string, years: int, coefficient?: ?float,
     *               in_service_date: string, acquisition_date?: ?string, frequency?: string, until?: ?string, decimals?: int}  $p
     * @return list<array{period_start: string, period_end: string, amount: float, cumulated: float, nbv: float}>
     */
    public static function schedule(array $p): array
    {
        $value = (float) $p['value'];
        $salvage = (float) ($p['salvage'] ?? 0);
        $base = max(0, $value - $salvage);
        $years = max(1, (int) $p['years']);
        $dec = $p['decimals'] ?? 0;
        $monthly = ($p['frequency'] ?? 'annual') === 'monthly';
        $until = isset($p['until']) && $p['until'] ? Carbon::parse($p['until']) : null;
        if ($base <= 0 || ($p['method'] ?? 'linear') === 'none') {
            return [];
        }

        $yearly = $p['method'] === 'degressive'
            ? self::degressiveYears($base, $years, (float) (($p['coefficient'] ?? null) ?: self::defaultCoefficient($years)), Carbon::parse($p['acquisition_date'] ?? $p['in_service_date'])->startOfMonth(), $dec)
            : self::linearYears($base, $years, Carbon::parse($p['in_service_date']), $dec);

        // Découpage en périodes (année ou mois), arrêt à la date de sortie
        $lines = [];
        foreach ($yearly as $y) {
            $start = Carbon::parse($y['start']);
            $end = Carbon::parse($y['end']);
            $periods = $monthly ? self::months($start, $end) : [[$start, $end]];
            $weights = array_map(fn ($pr) => $p['method'] === 'degressive' ? self::monthsBetween($pr[0], $pr[1]) : self::days360($pr[0], $pr[1]), $periods);
            $totalW = array_sum($weights) ?: 1;
            $done = 0.0;
            foreach ($periods as $i => [$ps, $pe]) {
                $amount = $i === count($periods) - 1 ? $y['amount'] - $done : round($y['amount'] * $weights[$i] / $totalW, $dec);
                $done += $amount;
                if ($until && $ps->gt($until)) {
                    break 2;
                }
                if ($until && $pe->gt($until)) {
                    // Sortie en cours de période : prorata jusqu'à la date de sortie
                    $w = $p['method'] === 'degressive' ? self::monthsBetween($ps, $until) : self::days360($ps, $until);
                    $amount = round($amount * $w / max(1, $weights[$i]), $dec);
                    $pe = $until->copy();
                    $lines[] = ['period_start' => $ps->toDateString(), 'period_end' => $pe->toDateString(), 'amount' => $amount];
                    break 2;
                }
                $lines[] = ['period_start' => $ps->toDateString(), 'period_end' => $pe->toDateString(), 'amount' => round($amount, $dec)];
            }
        }
        $cum = 0.0;
        foreach ($lines as &$l) {
            $cum = round($cum + $l['amount'], $dec);
            $l['cumulated'] = $cum;
            $l['nbv'] = round($value - $cum, $dec);
        }
        unset($l);

        return array_values(array_filter($lines, fn ($l) => abs($l['amount']) > 0.0001));
    }

    /** Annuités linéaires par exercice (prorata 30/360). */
    private static function linearYears(float $base, int $years, Carbon $start, int $dec): array
    {
        $endLife = $start->copy()->addYears($years)->subDay();
        $total = $years * 360;
        $out = [];
        $done = 0.0;
        $y = $start->year;
        while ($y <= $endLife->year) {
            $ps = $y === $start->year ? $start->copy() : Carbon::create($y, 1, 1);
            $pe = $y === $endLife->year ? $endLife->copy() : Carbon::create($y, 12, 31);
            $amount = $y === $endLife->year ? $base - $done : round($base * self::days360($ps, $pe) / $total, $dec);
            $done += $amount;
            $out[] = ['start' => $ps->toDateString(), 'end' => $pe->toDateString(), 'amount' => round($amount, $dec)];
            $y++;
        }

        return $out;
    }

    /** Annuités dégressives par exercice, bascule en linéaire. */
    private static function degressiveYears(float $base, int $years, float $coef, Carbon $start, int $dec): array
    {
        $rate = $coef / $years;
        $totalMonths = $years * 12;
        $endLife = $start->copy()->addMonths($totalMonths)->subDay();
        $nbv = $base;
        $used = 0;
        $out = [];
        $y = $start->year;
        while ($y <= $endLife->year && $nbv > 0.004) {
            $ps = $y === $start->year ? $start->copy() : Carbon::create($y, 1, 1);
            $pe = $y === $endLife->year ? $endLife->copy() : Carbon::create($y, 12, 31);
            $m = self::monthsBetween($ps, $pe);
            $remaining = $totalMonths - $used;
            if ($y === $endLife->year || $remaining <= $m) {
                $amount = $nbv;
            } else {
                $amount = max($nbv * $rate * $m / 12, $nbv * $m / $remaining);
            }
            $amount = round(min($amount, $nbv), $dec);
            $nbv = round($nbv - $amount, $dec);
            $used += $m;
            $out[] = ['start' => $ps->toDateString(), 'end' => $pe->toDateString(), 'amount' => $amount];
            $y++;
        }

        return $out;
    }

    private static function months(Carbon $start, Carbon $end): array
    {
        $out = [];
        $cur = $start->copy();
        while ($cur->lte($end)) {
            $me = $cur->copy()->endOfMonth()->startOfDay();
            $out[] = [$cur->copy(), $me->gt($end) ? $end->copy() : $me];
            $cur = $me->copy()->addDay();
        }

        return $out;
    }

    /** Nombre de mois (entamés) entre deux dates incluses. */
    private static function monthsBetween(Carbon $a, Carbon $b): int
    {
        return max(0, ($b->year - $a->year) * 12 + $b->month - $a->month + 1);
    }
}
