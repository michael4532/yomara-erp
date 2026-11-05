<?php

namespace App\Modules\Compta;

use App\Models\Company;
use Illuminate\Support\Carbon;

/**
 * Exercices comptables : année civile par défaut, ou exercice décalé (ex. du 1er juillet au 30 juin).
 * Un exercice est désigné par l'année de son premier jour (exercice 2025 = 01/07/2025 → 30/06/2026).
 */
class FiscalCalendar
{
    public static function startMonth(Company $c): int
    {
        $m = (int) data_get($c->settings, 'compta.fiscal_start_month', 1);

        return $m >= 1 && $m <= 12 ? $m : 1;
    }

    /** @return array{0: string, 1: string} premier et dernier jour de l'exercice */
    public static function period(Company $c, int $y): array
    {
        $start = Carbon::create($y, self::startMonth($c), 1);

        return [$start->toDateString(), $start->copy()->addYear()->subDay()->toDateString()];
    }

    /** Exercice (année du premier jour) qui contient la date. */
    public static function yearOf(Company $c, string $date): int
    {
        $d = Carbon::parse($date);

        return $d->month >= self::startMonth($c) ? $d->year : $d->year - 1;
    }

    public static function startOf(Company $c, string $date): string
    {
        return self::period($c, self::yearOf($c, $date))[0];
    }

    public static function label(Company $c, int $y): string
    {
        return self::startMonth($c) === 1 ? (string) $y : $y.'-'.($y + 1);
    }
}
