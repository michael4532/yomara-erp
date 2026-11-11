<?php

namespace App\Support;

/**
 * Prépare du HTML pour \DOMDocument::loadHTML sans « caractères spéciaux » cassés (reÃ§u au lieu de reçu).
 *
 * libxml lit le HTML en Latin-1 dès qu'il rencontre un <meta charset> (Outlook, Gmail, sites web) : les accents
 * UTF-8 deviennent illisibles. On garantit donc un texte UTF-8 valide, on retire les déclarations de jeu de caractères
 * et on écrit les caractères non ASCII en entités numériques : l'analyse ne dépend plus d'aucune détection.
 */
class Utf8Html
{
    public static function prepare(string $html): string
    {
        $html = self::toUtf8($html);
        // Déclarations de jeu de caractères : inutiles (tout est UTF-8) et sources d'erreur pour libxml
        $html = preg_replace('/<\?xml[^>]*\?>/i', '', $html) ?? $html;
        $html = preg_replace('/<meta\b[^>]*\bcharset\s*=[^>]*>/i', '', $html) ?? $html;

        return mb_encode_numericentity($html, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');
    }

    /** Texte en UTF-8 valide : converti depuis le jeu déclaré (ou Windows-1252) s'il ne l'est pas. */
    public static function toUtf8(string $s, ?string $declared = null): string
    {
        if ($s === '' || mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }
        if (! $declared && preg_match('/charset\s*=\s*["\']?([\w-]+)/i', $s, $m)) {
            $declared = $m[1];
        }
        $from = strtoupper((string) $declared);
        if ($from === '' || in_array($from, ['UTF-8', 'UTF8', 'ISO-8859-1', 'LATIN1', 'US-ASCII'], true)) {
            $from = 'Windows-1252'; // sur-ensemble de Latin-1, ce qu'envoient la plupart des logiciels
        }
        $out = @mb_convert_encoding($s, 'UTF-8', $from);

        return is_string($out) && mb_check_encoding($out, 'UTF-8') ? $out : (string) @mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    /**
     * Corrige un texte déjà abîmé (UTF-8 relu en Latin-1 / Windows-1252 : « reÃ§u », « Ã  », « â€™ ») ;
     * chaque séquence est réparée séparément : un texte qui mélange accents corrects et abîmés est aussi corrigé.
     * Utilisé pour réparer les messages déjà enregistrés.
     */
    public static function fixMojibake(?string $s): ?string
    {
        if ($s === null || $s === '' || ! preg_match('/[\x{00C2}-\x{00F4}][\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}\x{2013}\x{2014}\x{2018}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}]/u', $s)) {
            return $s;
        }
        $out = preg_replace_callback(
            '/[\x{00C2}-\x{00F4}][\x{0080}-\x{00BF}\x{0152}\x{0153}\x{0160}\x{0161}\x{0178}\x{017D}\x{017E}\x{0192}\x{02C6}\x{02DC}\x{2013}\x{2014}\x{2018}-\x{201E}\x{2020}-\x{2022}\x{2026}\x{2030}\x{2039}\x{203A}\x{20AC}\x{2122}\x{00C2}-\x{00F4}]+/u',
            function ($m) {
                $bytes = @mb_convert_encoding($m[0], 'Windows-1252', 'UTF-8');

                return is_string($bytes) && $bytes !== $m[0] && mb_check_encoding($bytes, 'UTF-8') && ! str_contains($bytes, '?') ? $bytes : $m[0];
            },
            $s
        );

        // « à » abîmé dont l'espace insécable est devenu une espace ordinaire (« Ã  »)
        return is_string($out) ? preg_replace(['/Ã (?= )/u', '/Ã(?= )/u'], 'à', $out) : $s;
    }
}
