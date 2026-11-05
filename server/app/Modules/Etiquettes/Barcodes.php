<?php

namespace App\Modules\Etiquettes;

use App\Modules\Core\Models\Product;

/** Codes EAN-13 « en magasin » (préfixes 20 à 29, réservés à l'usage interne par GS1). */
class Barcodes
{
    public static function checkDigit(string $twelve): int
    {
        $sum = 0;
        foreach (str_split($twelve) as $i => $d) {
            $sum += (int) $d * ($i % 2 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10;
    }

    public static function isValidEan13(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code) && self::checkDigit(substr($code, 0, 12)) === (int) $code[12];
    }

    /** 20 + 10 chiffres + clé ; on reprend après le plus grand code interne déjà attribué. */
    public static function nextInternal(): string
    {
        $max = Product::where('barcode', 'like', '20__________%')->pluck('barcode')->filter(fn ($b) => strlen($b) === 13)->map(fn ($b) => (int) substr($b, 2, 10))->max() ?? 0;
        do {
            $max++;
            $twelve = '20'.str_pad((string) $max, 10, '0', STR_PAD_LEFT);
            $code = $twelve.self::checkDigit($twelve);
        } while (Product::where('barcode', $code)->exists());

        return $code;
    }

    public static function nextPlu(): string
    {
        $max = (int) Product::whereNotNull('plu')->max('plu');

        return str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /** Étiquette de pesée : 21 + PLU (5) + poids en grammes (5) + clé. */
    public static function weight(string $plu, float $kg): string
    {
        $twelve = '21'.$plu.str_pad((string) (int) round($kg * 1000), 5, '0', STR_PAD_LEFT);

        return $twelve.self::checkDigit($twelve);
    }
}
