<?php

namespace App\Modules\TableauxExcel;

use PhpOffice\PhpSpreadsheet\Calculation\FunctionArray;
use PhpOffice\PhpSpreadsheet\Calculation\Functions;

/**
 * SOMME.SI.ENS / NB.SI.ENS rapides pour le pré-calcul des classeurs (critères simples : égalité, « * », « <> »,
 * jokers). Le moteur de PhpSpreadsheet évalue chaque critère comme une formule : trop lent sur des milliers de lignes.
 * Installé le temps de l'écriture du classeur, puis retiré ; Excel recalcule ensuite avec ses propres fonctions.
 */
class FastConditional extends FunctionArray
{
    private static ?array $saved = null;

    public static function install(): void
    {
        if (self::$saved !== null) {
            return;
        }
        self::$saved = ['SUMIFS' => FunctionArray::$phpSpreadsheetFunctions['SUMIFS'], 'COUNTIFS' => FunctionArray::$phpSpreadsheetFunctions['COUNTIFS']];
        FunctionArray::$phpSpreadsheetFunctions['SUMIFS']['functionCall'] = [self::class, 'sumifs'];
        FunctionArray::$phpSpreadsheetFunctions['COUNTIFS']['functionCall'] = [self::class, 'countifs'];
    }

    public static function uninstall(): void
    {
        if (self::$saved === null) {
            return;
        }
        foreach (self::$saved as $k => $v) {
            FunctionArray::$phpSpreadsheetFunctions[$k] = $v;
        }
        self::$saved = null;
    }

    public static function sumifs(mixed ...$args): float
    {
        $values = Functions::flattenArray(array_shift($args));
        $mask = self::mask($args, count($values));
        $t = 0.0;
        foreach ($values as $i => $v) {
            if (($mask[$i] ?? false) && is_numeric($v)) {
                $t += (float) $v;
            }
        }

        return $t;
    }

    public static function countifs(mixed ...$args): int
    {
        $first = Functions::flattenArray($args[0] ?? []);

        return count(array_filter(self::mask($args, count($first))));
    }

    /** @return bool[] */
    private static function mask(array $args, int $len): array
    {
        $mask = array_fill(0, $len, true);
        for ($k = 0; $k + 1 < count($args); $k += 2) {
            $range = array_values(Functions::flattenArray($args[$k]));
            $crit = $args[$k + 1];
            if (is_array($crit)) {
                $crit = Functions::flattenSingleValue($crit);
            }
            $test = self::matcher($crit);
            for ($i = 0; $i < $len; $i++) {
                if ($mask[$i] && ! $test($range[$i] ?? null)) {
                    $mask[$i] = false;
                }
            }
        }

        return $mask;
    }

    private static function matcher(mixed $crit): \Closure
    {
        if (is_int($crit) || is_float($crit)) {
            return fn ($v) => is_numeric($v) ? (float) $v == (float) $crit : (is_string($v) && is_numeric(trim($v)) && (float) $v == (float) $crit);
        }
        $c = (string) $crit;
        if ($c === '*') {
            return fn ($v) => is_string($v);
        }
        if ($c === '<>') {
            return fn ($v) => $v !== null && $v !== '';
        }
        if ($c === '' || $c === '=') {
            return fn ($v) => $v === null || $v === '';
        }
        foreach (['<>', '>=', '<=', '>', '<', '='] as $op) {
            if (str_starts_with($c, $op) && is_numeric(substr($c, strlen($op)))) {
                $n = (float) substr($c, strlen($op));

                return fn ($v) => is_numeric($v) && match ($op) {
                    '<>' => (float) $v != $n, '>=' => (float) $v >= $n, '<=' => (float) $v <= $n, '>' => (float) $v > $n, '<' => (float) $v < $n, default => (float) $v == $n,
                };
            }
        }
        if (str_starts_with($c, '=')) {
            $c = substr($c, 1);
        }
        if (strpbrk($c, '*?') !== false) {
            $re = '/^'.str_replace(['\*', '\?'], ['.*', '.'], preg_quote($c, '/')).'$/iu';

            return fn ($v) => $v !== null && preg_match($re, (string) $v) === 1;
        }
        $low = mb_strtolower($c);
        $num = is_numeric($c) ? (float) $c : null;

        return fn ($v) => ($num !== null && is_numeric($v)) ? (float) $v == $num : ($v !== null && mb_strtolower((string) $v) === $low);
    }
}
