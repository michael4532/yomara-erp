<?php

namespace App\Modules\Tresorerie\Momo;

/**
 * Lecture des SMS de confirmation Mobile Money (Wave, Orange Money, MTN MoMo, Moov Money — Côte d'Ivoire et UEMOA).
 * Ne retient que les encaissements (argent reçu) ; les envois, retraits, paiements émis sont marqués « sortants ».
 */
class MomoParser
{
    public const PROVIDERS = ['wave' => 'Wave', 'orange' => 'Orange Money', 'mtn' => 'MTN MoMo', 'moov' => 'Moov Money'];

    public static function amount(string $s): ?float
    {
        $s = trim($s);
        // « 25.000 », « 25 000 », « 25,000 » (séparateurs de milliers) ou « 25000,50 »
        if (preg_match('/^\d{1,3}([ .,\x{00A0}\x{202F}]\d{3})+$/u', $s)) {
            return (float) preg_replace('/\D/', '', $s);
        }
        if (preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $s, $m)) {
            return (float) ($m[1].(isset($m[2]) ? '.'.$m[2] : ''));
        }

        return null;
    }

    /** Numéro réduit à ses 10 derniers chiffres (format ivoirien), indicatif 225 retiré. */
    public static function phone(?string $s): ?string
    {
        $d = preg_replace('/\D/', '', (string) $s);
        if (strlen($d) < 8) {
            return null;
        }
        if (str_starts_with($d, '225') && strlen($d) > 10) {
            $d = substr($d, 3);
        }

        return strlen($d) > 10 ? substr($d, -10) : $d;
    }

    public static function samePhone(?string $a, ?string $b): bool
    {
        $a = self::phone($a);
        $b = self::phone($b);

        return $a && $b && substr($a, -8) === substr($b, -8);
    }

    public static function provider(string $text, ?string $sender): ?string
    {
        $h = mb_strtolower(($sender ?? '').' '.$text);

        return match (true) {
            str_contains($h, 'wave') => 'wave',
            str_contains($h, 'orange') || preg_match('/\bom\b/', $h) === 1 => 'orange',
            str_contains($h, 'mtn') || str_contains($h, 'momo') => 'mtn',
            str_contains($h, 'moov') || str_contains($h, 'flooz') => 'moov',
            default => null,
        };
    }

    /**
     * @return array{direction: string, amount: ?float, phone: ?string, name: ?string, reference: ?string, provider: ?string, memo: ?string}
     */
    public static function parse(string $text, ?string $sender = null): array
    {
        $t = preg_replace('/\s+/u', ' ', trim($text));
        $low = mb_strtolower($t);
        $out = ['direction' => 'unknown', 'amount' => null, 'phone' => null, 'name' => null, 'reference' => null, 'provider' => self::provider($t, $sender), 'memo' => null];
        $num = '(\d{1,3}(?:[ .,\x{00A0}\x{202F}]\d{3})+|\d+(?:[.,]\d{1,2})?)';
        $cur = '(?:\s*(?:F\s?CFA|FCFA|XOF|CFA|F)\b)';

        $in = preg_match('/\b(re[çc]u|recu|reception|réception|dépôt reçu|transfert re[çc]u|a envoy[ée] .{0,40} sur votre)\b/iu', $t) === 1;
        $outgoing = preg_match('/\b(vous avez envoy[ée]|transfert (?:de \d.*)?vers|retrait|paiement (?:effectu[ée]|de \d)|vous avez pay[ée]|achat|d[ée]bit[ée])\b/iu', $t) === 1 && ! preg_match('/vous avez re[çc]u/iu', $t);
        $out['direction'] = $outgoing ? 'out' : ($in ? 'in' : 'unknown');

        // Montant : « reçu 25 000 FCFA », « reçu un transfert de 25000 FCFA », « 25.000F reçus de »
        if (preg_match('/re[çc]u(?:s)?\s+(?:un\s+(?:transfert|paiement|d[ée]p[oô]t)\s+de\s+|la somme de\s+|)'.$num.$cur.'/iu', $t, $m)
            || preg_match('/(?:transfert|d[ée]p[oô]t|paiement) de\s+'.$num.$cur.'/iu', $t, $m)
            || preg_match('/'.$num.$cur.'\s+re[çc]u/iu', $t, $m)
            || preg_match('/montant\s*:?\s*'.$num.$cur.'?/iu', $t, $m)) {
            $out['amount'] = self::amount($m[1]);
        }
        // Expéditeur : « de NOM (07 07 07 07 07) », « du 0707070707 (NOM) », « de 2250505050505 »
        if (preg_match('/\b(?:de|du|from)\s+([^()0-9.,:;]{2,60}?)\s*\(\s*(\+?[\d ]{8,18})\s*\)/iu', $t, $m)) {
            $out['name'] = trim($m[1]);
            $out['phone'] = self::phone($m[2]);
        } elseif (preg_match('/\b(?:de|du|from|exp[ée]diteur\s*:?)\s+(?:la part de\s+)?(\+?\d[\d ]{7,16}\d)(?:\s*\(\s*([^)]{2,60})\))?/iu', $t, $m)) {
            $out['phone'] = self::phone($m[1]);
            $out['name'] = isset($m[2]) ? trim($m[2]) : null;
        }
        if (! $out['name'] && preg_match('/\b(?:de|du)\s+([A-ZÉÈÀÂÎÔÛÇ][A-Za-zÀ-ÿ\'\- ]{2,50}?)(?:\s*[.,]|\s+le\s|\s+\d|\s*$)/u', $t, $m) && ! preg_match('/\d/', $m[1])) {
            $out['name'] = trim($m[1]);
        }
        if (preg_match('/(?:r[ée]f(?:[ée]rence)?|transaction id|trans(?:action)?\s?id|id transaction|txn|num[ée]ro de transaction|\bID)\s*[:.#]?\s*([A-Z0-9][A-Z0-9.\-_]{4,40})/iu', $t, $m)) {
            $out['reference'] = rtrim($m[1], '.');
        }
        if (preg_match('/(?:message|motif|note|libell[ée])\s*:\s*([^.]{1,120})/iu', $t, $m)) {
            $out['memo'] = trim($m[1]);
        }
        if ($out['direction'] === 'unknown' && $out['amount'] && ($out['phone'] || $out['name'])) {
            $out['direction'] = 'in';
        }

        return $out;
    }
}
