<?php

namespace App\Messaging;

/** Numéros au format international (E.164) ; indicatif par défaut : Côte d'Ivoire (225). */
class Phone
{
    public static function e164(?string $raw, string $country = '225'): ?string
    {
        if ($raw === null) {
            return null;
        }
        $d = preg_replace('/[^0-9+]/', '', $raw);
        if ($d === '' || $d === '+') {
            return null;
        }
        if (str_starts_with($d, '00')) {
            $d = '+'.substr($d, 2);
        }
        if (! str_starts_with($d, '+')) {
            $d = '+'.(str_starts_with($d, $country) && strlen($d) > 10 ? $d : $country.$d);
        }
        $digits = substr($d, 1);

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $d : null;
    }

    public static function digits(?string $raw, string $country = '225'): ?string
    {
        $e = self::e164($raw, $country);

        return $e ? substr($e, 1) : null;
    }
}
