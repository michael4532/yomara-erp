<?php

namespace App\Support;

/**
 * Nettoie le HTML conçu par un utilisateur (e-mails, pages, annonces) en gardant la mise en forme :
 * retire scripts, cadres, formulaires et attributs d'événement ; n'accepte que des liens http(s), mailto, tel,
 * ancres et variables {{…}} ; neutralise les CSS dangereuses. Analyse réelle du document (pas d'expressions régulières).
 */
class HtmlSanitizer
{
    protected const DROP = ['script', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'link', 'meta', 'form', 'input', 'button',
        'select', 'textarea', 'option', 'noscript', 'template', 'portal', 'svg', 'math', 'param', 'audio', 'video', 'source', 'track', 'dialog'];

    protected const URL_ATTRS = ['href', 'src', 'action', 'formaction', 'background', 'poster', 'cite', 'longdesc', 'usemap', 'xlink:href', 'srcset', 'data', 'codebase', 'dynsrc', 'lowsrc'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $html = Utf8Html::prepare($html); // accents préservés quel que soit le <meta charset> d'origine
        $full = (bool) preg_match('/<html[\s>]/i', $html);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.($full ? $html : '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="__root">'.$html.'</div></body></html>'), LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xp = new \DOMXPath($doc);
        foreach (iterator_to_array($xp->query('//comment()')) as $n) {
            $n->parentNode?->removeChild($n);
        }
        foreach (self::DROP as $tag) {
            foreach (iterator_to_array($doc->getElementsByTagName($tag)) as $n) {
                // Le <meta charset> ajouté pour l'analyse est remis plus bas
                $n->parentNode?->removeChild($n);
            }
        }
        foreach (iterator_to_array($xp->query('//*')) as $el) {
            /** @var \DOMElement $el */
            if ($el->nodeName === 'style') {
                $el->textContent = self::css($el->textContent);

                continue;
            }
            foreach (iterator_to_array($el->attributes) as $a) {
                $name = strtolower($a->nodeName);
                $val = (string) $a->nodeValue;
                if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'formaction', 'xmlns:xlink'], true)) {
                    $el->removeAttribute($a->nodeName);
                } elseif (in_array($name, self::URL_ATTRS, true) && ! self::safeUrl($val, $name === 'src' || $name === 'background')) {
                    $el->removeAttribute($a->nodeName);
                } elseif ($name === 'style') {
                    $el->setAttribute('style', self::css($val));
                } elseif ($name === 'http-equiv') {
                    $el->removeAttribute($a->nodeName);
                }
            }
        }

        if ($full) {
            $out = self::vars((string) $doc->saveHTML($doc->documentElement));
            $out = preg_replace('/<head>/i', '<head><meta charset="utf-8">', (string) $out, 1);

            return '<!doctype html>'.$out;
        }
        $root = $doc->getElementById('__root');
        $out = '';
        foreach ($root ? iterator_to_array($root->childNodes) : [] as $c) {
            $out .= $doc->saveHTML($c);
        }

        return self::vars($out);
    }

    /** Les variables {{…}} des liens sont encodées par l'analyseur : on les rétablit. */
    protected static function vars(string $html): string
    {
        return preg_replace('/%7B%7B\s*([a-z_]+)\s*%7D%7D/i', '{{$1}}', $html);
    }

    public static function safeUrl(string $u, bool $image = false): bool
    {
        // Espaces et caractères de contrôle insérés dans « java\tscript: » : retirés avant contrôle
        $u = strtolower(preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($u, ENT_QUOTES | ENT_HTML5)));
        // Variable seule : uniquement les liens fournis par la plateforme ({{lien_desinscription}}, {{lien_app}}…).
        // « {{prenom}}javascript:… » est refusé : la variable vide laisserait passer le schéma dangereux.
        if ($u === '' || str_starts_with($u, '#') || str_starts_with($u, '/') && ! str_starts_with($u, '//') || preg_match('/^\{\{lien_[a-z_]+\}\}$/', $u)) {
            return true;
        }
        if ($image && preg_match('#^data:image/(png|jpe?g|gif|webp);base64,#', $u)) {
            return true;
        }

        return (bool) preg_match('#^(https?:|mailto:|tel:|//)#', $u);
    }

    /** CSS : pas de javascript:, expression(), behavior, @import ni -moz-binding (retirés jusqu'à stabilité). */
    public static function css(string $css): string
    {
        $css = preg_replace('/\/\*.*?\*\//s', '', $css);
        do {
            $before = $css;
            $css = preg_replace('/(j\s*a\s*v\s*a\s*s\s*c\s*r\s*i\s*p\s*t|v\s*b\s*s\s*c\s*r\s*i\s*p\s*t)\s*:|expression\s*\(|behavior\s*:|-moz-binding|@import|\\[0-9a-f]{1,6}\s?/i', '', $css);
        } while ($css !== $before);

        return $css;
    }
}
