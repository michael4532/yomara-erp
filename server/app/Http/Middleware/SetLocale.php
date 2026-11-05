<?php

namespace App\Http\Middleware;

use App\Support\Tr;
use Closure;
use Illuminate\Http\Request;

/** Langue de la requête : en-tête X-Locale envoyé par l'application ; français par défaut (pages publiques, API externes). */
class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $loc = strtolower(substr((string) ($request->header('X-Locale') ?: 'fr'), 0, 2));
        app()->setLocale(in_array($loc, Tr::LOCALES, true) ? $loc : 'fr');
        \Carbon\Carbon::setLocale(app()->getLocale());

        return $next($request);
    }
}
