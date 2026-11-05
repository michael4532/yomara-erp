<?php

namespace App\Support;

/**
 * Traduction côté serveur (PDF, e-mails) : le français est la clé, lang/<langue>.json donne la traduction,
 * avec des modèles pour les textes variables (« {} fiche(s) »). Même dictionnaire que l'application.
 */
class Tr
{
    public const LOCALES = ['fr', 'en'];

    /** @var array<string, array{0: array<string,string>, 1: array<int, array{0:string,1:string}>}> */
    protected static array $dicts = [];

    protected static function dict(string $locale): array
    {
        if (isset(self::$dicts[$locale])) {
            return self::$dicts[$locale];
        }
        $exact = [];
        $patterns = [];
        $file = lang_path($locale.'.json');
        if ($locale !== 'fr' && is_file($file)) {
            foreach (json_decode((string) file_get_contents($file), true) ?: [] as $k => $v) {
                if ($v === '' || $v === null) {
                    continue;
                }
                if (str_contains($k, '{}')) {
                    if (! preg_match('/\p{L}{2,}/u', str_replace('{}', '', $k))) {
                        continue; // un modèle sans texte fixe correspondrait à tout
                    }
                    $re = '/^'.implode('(.+?)', array_map(fn ($p) => preg_quote($p, '/'), explode('{}', $k))).'$/su';
                    $patterns[] = [$re, (string) $v, mb_strlen(str_replace('{}', '', $k))];
                } else {
                    $exact[$k] = (string) $v;
                }
            }
            usort($patterns, fn ($a, $b) => strlen($b[0]) <=> strlen($a[0]));
        }

        return self::$dicts[$locale] = [$exact, $patterns];
    }

    /** Gabarit (avec {}) ou texte exact : sa traduction telle quelle, sans remplacer les {} (pour les scripts des pages). */
    public static function exactOrSelf(string $s, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        if ($locale === 'fr') {
            return $s;
        }
        $file = lang_path($locale.'.json');
        static $raw = [];
        $raw[$locale] ??= is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];

        return (string) (($raw[$locale][$s] ?? '') ?: $s);
    }

    public static function t(?string $s, ?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        if ($s === null || $s === '' || $locale === 'fr') {
            return $s;
        }
        [$exact, $patterns] = self::dict($locale);
        if (isset($exact[$s])) {
            return $exact[$s];
        }
        if (mb_strlen($s) < 400) {
            foreach ($patterns as [$re, $tpl]) {
                if (preg_match($re, $s, $m)) {
                    $i = 0;

                    return preg_replace_callback('/\{\}/', function () use (&$i, $m, $locale, $s) {
                        $g = $m[++$i] ?? '';

                        return $g === $s ? $g : self::t($g, $locale);
                    }, $tpl);
                }
            }
        }

        return $s;
    }

    /** Gabarit traduit puis complété : f('Votre formule {} expire le {}.', ['Starter', '12/11/2026'], 'en'). */
    public static function f(string $tpl, array $args = [], ?string $locale = null): string
    {
        $out = self::exactOrSelf($tpl, $locale);
        foreach ($args as $a) {
            $pos = strpos($out, '{}');
            if ($pos === false) {
                break;
            }
            $out = substr_replace($out, (string) $a, $pos, 2);
        }

        return $out;
    }

    /**
     * Version prudente pour les pages web : texte exact, ou gabarit dont la partie fixe couvre au moins la moitié du texte
     * (« Choisir {} », « {} économisés »). Évite qu'un gabarit très court (« Le {} ») ne traduise une phrase à moitié.
     */
    public static function strict(string $s, string $locale, int $ratio = 2): ?string
    {
        if ($locale === 'fr' || $s === '') {
            return null;
        }
        [$exact, $patterns] = self::dict($locale);
        if (isset($exact[$s])) {
            return $exact[$s];
        }
        $len = mb_strlen($s);
        if ($len >= 400) {
            return null;
        }
        foreach ($patterns as [$re, $tpl, $lit]) {
            if ($lit * $ratio < $len || ! preg_match($re, $s, $m)) {
                continue;
            }
            $i = 0;

            return preg_replace_callback('/\{\}/', function () use (&$i, $m, $locale) {
                $g = $m[++$i] ?? '';

                return self::strict($g, $locale, 3) ?? $g;
            }, $tpl);
        }

        return null;
    }

    public static function flush(): void
    {
        self::$dicts = [];
    }
}
