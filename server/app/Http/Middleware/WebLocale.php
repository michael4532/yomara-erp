<?php

namespace App\Http\Middleware;

use App\Support\HtmlTranslator;
use App\Support\Tr;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Langue des pages publiques (site, inscription, mot de passe…) : ?lang=en, puis le choix mémorisé (cookie),
 * puis la langue du navigateur ; français par défaut. Les pages sont écrites en français et traduites à la volée
 * avec le même dictionnaire que l'application (lang/<langue>.json).
 */
class WebLocale
{
    public const COOKIE = 'yl_lang';

    public static function detect(Request $r): string
    {
        $q = strtolower(substr((string) $r->query('lang', ''), 0, 2));
        if (in_array($q, Tr::LOCALES, true)) {
            return $q;
        }
        $c = strtolower(substr((string) $r->cookie(self::COOKIE, ''), 0, 2));
        if (in_array($c, Tr::LOCALES, true)) {
            return $c;
        }

        if (! config('erp.web_browser_locale', true)) {
            return 'fr';
        }
        // navigateur : le français dès qu'il figure parmi les langues acceptées (public francophone d'abord)
        $langs = array_map(fn ($l) => strtolower(substr($l, 0, 2)), $r->getLanguages());
        if (! $langs || in_array('fr', $langs, true)) {
            return 'fr';
        }

        return $r->getPreferredLanguage(Tr::LOCALES) ?: 'fr';
    }

    public function handle(Request $request, Closure $next): Response
    {
        $loc = self::detect($request);
        app()->setLocale($loc);
        \Carbon\Carbon::setLocale($loc);
        $response = $next($request);
        if ($request->query('lang') && in_array($loc, Tr::LOCALES, true)) {
            $response->headers->setCookie(cookie()->forever(self::COOKIE, $loc, null, null, $request->isSecure(), false, false, 'lax'));
        }
        $response->headers->set('Content-Language', $loc);
        $response->setVary(array_unique([...$response->getVary(), 'Accept-Language', 'Cookie']));
        $type = (string) $response->headers->get('Content-Type');
        if ($loc !== 'fr' && method_exists($response, 'getContent') && ($type === '' || str_contains($type, 'text/html')) && ! $response->isRedirection()) {
            $html = (string) $response->getContent();
            if ($html !== '' && str_contains($html, '<')) {
                // pages identiques (site vitrine) : traduction gardée en cache
                $response->setContent(\Illuminate\Support\Facades\Cache::remember('webtr:'.$loc.':'.md5($html), now()->addHours(12), fn () => HtmlTranslator::translate($html, $loc)));
            }
        }

        return $response;
    }
}
