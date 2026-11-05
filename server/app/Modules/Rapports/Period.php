<?php

namespace App\Modules\Rapports;

use Illuminate\Support\Carbon;

/** Périodes relatives (« ce mois-ci », « 12 derniers mois »…) recalculées à chaque exécution. */
class Period
{
    public const PRESETS = [
        'today' => 'Aujourd\'hui', 'yesterday' => 'Hier',
        'this_week' => 'Cette semaine', 'last_week' => 'La semaine dernière',
        'this_month' => 'Ce mois-ci', 'last_month' => 'Le mois dernier',
        'this_quarter' => 'Ce trimestre', 'last_quarter' => 'Le trimestre dernier',
        'this_year' => 'Cette année', 'last_year' => 'L\'année dernière', 'ytd' => 'Depuis le 1er janvier',
        'last_7_days' => '7 derniers jours', 'last_30_days' => '30 derniers jours', 'last_90_days' => '90 derniers jours',
        'last_12_months' => '12 derniers mois', 'all' => 'Toutes dates', 'custom' => 'Dates choisies',
    ];

    public const COMPARE = ['previous_period' => 'Période précédente', 'previous_year' => 'Même période l\'an dernier'];

    /** @return array{0: ?Carbon, 1: ?Carbon} */
    public static function range(string $preset, ?string $from = null, ?string $to = null, ?Carbon $today = null): array
    {
        $t = ($today ?? now())->copy()->startOfDay();

        return match ($preset) {
            'today' => [$t->copy(), $t->copy()],
            'yesterday' => [$t->copy()->subDay(), $t->copy()->subDay()],
            'this_week' => [$t->copy()->startOfWeek(), $t->copy()->endOfWeek()->startOfDay()],
            'last_week' => [$t->copy()->subWeek()->startOfWeek(), $t->copy()->subWeek()->endOfWeek()->startOfDay()],
            'this_month' => [$t->copy()->startOfMonth(), $t->copy()->endOfMonth()->startOfDay()],
            'last_month' => [$t->copy()->subMonthNoOverflow()->startOfMonth(), $t->copy()->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'this_quarter' => [$t->copy()->startOfQuarter(), $t->copy()->endOfQuarter()->startOfDay()],
            'last_quarter' => [$t->copy()->subQuarterNoOverflow()->startOfQuarter(), $t->copy()->subQuarterNoOverflow()->endOfQuarter()->startOfDay()],
            'this_year' => [$t->copy()->startOfYear(), $t->copy()->endOfYear()->startOfDay()],
            'last_year' => [$t->copy()->subYearNoOverflow()->startOfYear(), $t->copy()->subYearNoOverflow()->endOfYear()->startOfDay()],
            'ytd' => [$t->copy()->startOfYear(), $t->copy()],
            'last_7_days' => [$t->copy()->subDays(6), $t->copy()],
            'last_30_days' => [$t->copy()->subDays(29), $t->copy()],
            'last_90_days' => [$t->copy()->subDays(89), $t->copy()],
            'last_12_months' => [$t->copy()->startOfMonth()->subMonthsNoOverflow(11), $t->copy()->endOfMonth()->startOfDay()],
            'custom' => [$from ? Carbon::parse($from)->startOfDay() : null, $to ? Carbon::parse($to)->startOfDay() : null],
            default => [null, null],
        };
    }

    /** Période de comparaison. */
    public static function previous(?Carbon $from, ?Carbon $to, string $mode): array
    {
        if (! $from || ! $to) {
            return [null, null];
        }
        if ($mode === 'previous_year') {
            return [$from->copy()->subYearNoOverflow(), $to->copy()->subYearNoOverflow()];
        }
        // Mois entiers : on recule d'autant de mois (février après mars…), sinon d'autant de jours.
        if ($from->day === 1 && $to->copy()->addDay()->day === 1) {
            $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;

            return [$from->copy()->subMonthsNoOverflow($months), $from->copy()->subDay()];
        }
        $days = $from->diffInDays($to) + 1;

        return [$from->copy()->subDays($days), $from->copy()->subDay()];
    }

    public static function label(string $preset, ?Carbon $from, ?Carbon $to): string
    {
        if (! $from && ! $to) {
            return 'Toutes dates';
        }
        $f = fn (?Carbon $d) => $d?->format('d/m/Y');
        $range = $from && $to ? ($from->equalTo($to) ? $f($from) : 'du '.$f($from).' au '.$f($to)) : ($from ? 'depuis le '.$f($from) : 'jusqu\'au '.$f($to));

        return $preset !== 'custom' ? (self::PRESETS[$preset] ?? $preset).' ('.$range.')' : ucfirst($range);
    }
}
