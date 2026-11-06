<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Assistant d'installation : hors du groupe "web" (pas de session ni
            // de cookies chiffrés, car APP_KEY n'existe pas encore à ce stade).
            Route::middleware([])->group(base_path('routes/install.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Désinscription « en un clic » depuis les messageries (RFC 8058) : pas de jeton CSRF
        $middleware->validateCsrfTokens(except: ['m/u/*', 'c/u/*', 'f/*', 'site/*', 'avis/*', 'note/*', 'q/*', 'lp/*']);
        // Sites vitrines servis par sous-domaine ou par le domaine du client (avant le routage)
        $middleware->append(\App\Http\Middleware\ServeSiteByHost::class);
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);
        // Pas de page de connexion Laravel : un invité reçoit un 401 JSON (API) ou est renvoyé vers l'application
        $middleware->redirectGuestsTo(fn ($request) => $request->is('api/*') ? null : url('/app/'));
        $middleware->api(append: [\App\Http\Middleware\ApiKeyScope::class, \App\Http\Middleware\SetLocale::class, \App\Http\Middleware\Idempotency::class]);
        $middleware->alias([
            'installed' => \App\Http\Middleware\EnsureInstalled::class,
            'tenant' => \App\Http\Middleware\SetTenant::class,
            'presence' => \App\Http\Middleware\TrackPresence::class,
            'module' => \App\Http\Middleware\RequireModule::class,
            'perm' => \App\Http\Middleware\RequirePermission::class,
            'superadmin' => \App\Http\Middleware\SuperAdminOnly::class,
            'weblang' => \App\Http\Middleware\WebLocale::class,
        ]);
        $middleware->web(prepend: [\App\Http\Middleware\EnsureInstalled::class]);
        // L'app Flutter (bureau / web sur un autre domaine) appelle l'API : CORS ouvert sur /api.
        // Proxys de confiance : seulement ceux déclarés (TRUSTED_PROXIES, ex. adresses Cloudflare). Sinon, les en-têtes
        // X-Forwarded-* d'un visiteur sont ignorés : impossible de changer d'adresse IP pour contourner les limites.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($proxies) {
            $middleware->trustProxies(at: $proxies === ['*'] ? '*' : $proxies,
                headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR | \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO | \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn ($request) => $request->is('api/*') || $request->expectsJson());
    })->create();
