<?php

namespace App\Support;

/** Montant en toutes lettres (français) : « sept cent soixante-quatre mille six cent quarante ». */
class FrenchNumber
{
    protected const UNITS = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix', 'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];

    protected const TENS = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante'];

    public static function words(int $n): string
    {
        if ($n < 0) {
            return 'moins '.self::words(-$n);
        }
        if ($n === 0) {
            return 'zéro';
        }
        $parts = [];
        foreach ([[1_000_000_000, 'milliard'], [1_000_000, 'million']] as [$size, $name]) {
            if ($n >= $size) {
                $q = intdiv($n, $size);
                $parts[] = self::below1000($q, false).' '.$name.($q > 1 ? 's' : '');
                $n %= $size;
            }
        }
        if ($n >= 1000) {
            $q = intdiv($n, 1000);
            $parts[] = $q === 1 ? 'mille' : self::below1000($q, false).' mille';
            $n %= 1000;
        }
        if ($n > 0) {
            $parts[] = self::below1000($n, true);
        }

        return implode(' ', $parts);
    }

    /** $final : dernier groupe (« quatre-vingts », « deux cents » prennent le s). */
    protected static function below1000(int $n, bool $final): string
    {
        $out = [];
        $h = intdiv($n, 100);
        $r = $n % 100;
        if ($h > 0) {
            $out[] = $h === 1 ? 'cent' : self::UNITS[$h].' cent'.($r === 0 && $final ? 's' : '');
        }
        if ($r > 0) {
            $out[] = self::below100($r, $final);
        }

        return implode(' ', $out);
    }

    protected static function below100(int $n, bool $final): string
    {
        if ($n < 20) {
            return self::UNITS[$n];
        }
        $t = intdiv($n, 10);
        $u = $n % 10;
        if ($t === 7 || $t === 9) {
            $base = $t === 7 ? 'soixante' : 'quatre-vingt';
            $rest = 10 + $u;

            return $base.($t === 7 && $u === 1 ? ' et ' : '-').self::UNITS[$rest];
        }
        if ($t === 8) {
            return $u === 0 ? 'quatre-vingt'.($final ? 's' : '') : 'quatre-vingt-'.self::UNITS[$u];
        }

        return self::TENS[$t].($u === 0 ? '' : ($u === 1 ? ' et un' : '-'.self::UNITS[$u]));
    }
}
