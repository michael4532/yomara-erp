<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * En-têtes de sécurité communs : pas de devinette du type de fichier, référent limité, HTTPS imposé au navigateur
 * une fois le site servi en HTTPS. Les pages faites pour être intégrées ailleurs (formulaires, sites) restent intégrables.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $res = $next($request);
        $h = $res->headers;
        if (! $h->has('X-Content-Type-Options')) {
            $h->set('X-Content-Type-Options', 'nosniff');
        }
        if (! $h->has('Referrer-Policy')) {
            $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        if ($request->isSecure() && ! $h->has('Strict-Transport-Security')) {
            $h->set('Strict-Transport-Security', 'max-age=15552000');
        }
        // L'API ne doit jamais être affichée dans un cadre
        if ($request->is('api/*') && ! $h->has('X-Frame-Options')) {
            $h->set('X-Frame-Options', 'DENY');
        }

        return $res;
    }
}
