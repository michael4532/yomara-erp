<?php

namespace App\Modules\Rapports;

use Illuminate\Support\Facades\DB;

/** Expressions SQL portables (SQLite pour les tests et petites installations, MySQL / MariaDB en production). */
class Sql
{
    public static function sqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    public static function concat(string ...$parts): string
    {
        return self::sqlite() ? '('.implode(' || ', $parts).')' : 'CONCAT('.implode(', ', $parts).')';
    }

    public static function day(string $col): string
    {
        return "DATE($col)";
    }

    public static function bucket(string $unit, string $col): string
    {
        $s = self::sqlite();

        return match ($unit) {
            'day' => "DATE($col)",
            'week' => $s ? "strftime('%Y-S%W', $col)" : "DATE_FORMAT($col, '%x-S%v')",
            'month' => $s ? "strftime('%Y-%m', $col)" : "DATE_FORMAT($col, '%Y-%m')",
            'quarter' => $s ? "(strftime('%Y', $col) || '-T' || ((CAST(strftime('%m', $col) AS INTEGER) + 2) / 3))" : "CONCAT(YEAR($col), '-T', QUARTER($col))",
            'year' => $s ? "strftime('%Y', $col)" : "DATE_FORMAT($col, '%Y')",
            'weekday' => $s ? "CAST(strftime('%w', $col) AS INTEGER)" : "(DAYOFWEEK($col) - 1)",
        };
    }

    /** Années écoulées depuis une date. */
    public static function yearsSince(string $col): string
    {
        return self::sqlite() ? "((julianday('now') - julianday($col)) / 365.25)" : "(DATEDIFF(CURDATE(), $col) / 365.25)";
    }
}
