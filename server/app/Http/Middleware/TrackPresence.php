<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Met à jour last_seen_at / plateforme / IP de l'utilisateur.
 * Écriture limitée (au plus toutes les N secondes) pour ne pas charger la base.
 * Le client envoie X-Client-Platform : windows | macos | linux | web | android | ios.
 */
class TrackPresence
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $user = $request->user();
        if ($user && ! $request->attributes->get('presence.skip')) {
            $platform = substr((string) $request->header('X-Client-Platform', 'web'), 0, 30);
            $stale = ! $user->last_seen_at
                || $user->last_seen_at->lt(now()->subSeconds(config('erp.presence_write_every')))
                || $user->last_platform !== $platform;

            if ($stale) {
                $user->forceFill([
                    'last_seen_at' => now(),
                    'last_platform' => $platform,
                    'last_ip' => $request->ip(),
                ])->saveQuietly();
                \App\Services\PlatformActivity::record($user, $platform);
            }
        }

        // Installation sous licence : vérification auprès de la plateforme toutes les 6 h, après la réponse
        if ($user && config('erp.mode') === 'single' && \App\Licensing\LicenseClient::due()) {
            app()->terminating(fn () => rescue(fn () => \App\Licensing\LicenseClient::check(), null, false));
        }

        return $response;
    }
}
