<?php

namespace App\Support;

/**
 * Traduit une page HTML rédigée en français : textes entre balises, attributs lisibles (placeholder, title, alt,
 * aria-label, meta description) et chaînes des scripts qui sont des phrases connues du dictionnaire.
 * Seules les correspondances du dictionnaire sont remplacées : le code et les données saisies restent intacts.
 */
class HtmlTranslator
{
    public static function translate(string $html, string $locale): string
    {
        if ($locale === 'fr') {
            return $html;
        }
        $html = preg_replace('/<html\b([^>]*)\blang="fr"/i', '<html$1lang="'.$locale.'"', $html, 1);
        // On sépare scripts / styles du reste
        $parts = preg_split('#(<script\b[^>]*>.*?</script>|<style\b[^>]*>.*?</style>|<!--.*?-->)#si', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $i => $p) {
            if ($p === '') {
                continue;
            }
            if (preg_match('#^<script\b#i', $p)) {
                $parts[$i] = self::script($p, $locale);
            } elseif (! preg_match('#^(<style\b|<!--)#i', $p)) {
                $parts[$i] = self::markup($p, $locale);
            }
        }

        return implode('', $parts);
    }

    protected static function t(string $s, string $locale): ?string
    {
        $norm = trim(preg_replace('/\s+/u', ' ', $s));
        if ($norm === '' || ! preg_match('/\p{L}{2}/u', $norm)) {
            return null;
        }
        $t = Tr::strict($norm, $locale);

        return $t !== null && $t !== $norm ? $t : null;
    }

    protected static function markup(string $html, string $locale): string
    {
        $orig = $html;
        // textes entre balises
        $html = preg_replace_callback('/>([^<>]++)</u', function ($m) use ($locale) {
            $raw = $m[1];
            $text = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $t = self::t($text, $locale);
            if ($t === null) {
                return $m[0];
            }
            preg_match('/^\s*/u', $raw, $lead);
            preg_match('/\s*$/u', $raw, $trail);

            return '>'.$lead[0].htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8', false).$trail[0].'<';
        }, $html) ?? $orig;

        // attributs lisibles
        $orig = $html;

        return preg_replace_callback('/\b(placeholder|title|alt|aria-label|data-label|data-cat|content)="([^"]*)"/u', function ($m) use ($locale) {
            if ($m[1] === 'content' && ! preg_match('/\s/', $m[2])) {
                return $m[0]; // content="width=device-width" etc. : pas une phrase
            }
            $t = self::t(html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), $locale);

            return $t === null ? $m[0] : $m[1].'="'.htmlspecialchars($t, ENT_QUOTES | ENT_HTML5, 'UTF-8', false).'"';
        }, $html) ?? $orig;
    }

    /** Chaînes JSON ("…") et JS ('…') d'un script : remplacées seulement si le dictionnaire les connaît. */
    protected static function script(string $js, string $locale): string
    {
        $out = preg_replace_callback('/"((?:[^"\\\\\n]++|\\\\.)*+)"/u', function ($m) use ($locale) {
            $s = json_decode('"'.$m[1].'"');
            if (! is_string($s)) {
                return $m[0];
            }
            $t = self::t($s, $locale);

            return $t === null ? $m[0] : json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
        }, $js);
        $js = $out ?? $js; // expression trop lourde : script laissé tel quel

        $out = preg_replace_callback("/'((?:[^'\\\\\\n]++|\\\\.)*+)'/u", function ($m) use ($locale) {
            $s = str_replace(["\\'", '\\\\'], ["'", '\\'], $m[1]);
            $t = self::t($s, $locale);

            return $t === null ? $m[0] : "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $t)."'";
        }, $js);

        return $out ?? $js;
    }
}
