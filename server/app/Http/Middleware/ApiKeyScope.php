<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/** Clés d'API : une clé « lecture seule » ne peut pas modifier les données ; aucune clé ne gère les intégrations, les comptes ni les réglages sensibles. */
class ApiKeyScope
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken() ? $request->user('sanctum')?->currentAccessToken() : null;
        if ($token instanceof PersonalAccessToken && str_starts_with((string) $token->name, 'api:')) {
            if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && ! $token->can('write')) {
                return response()->json(['message' => 'Clé d\'API en lecture seule.'], 403);
            }
            // Réglages sensibles (moyens de paiement, canaux d'envoi, boîtes mail, licences, achats de modules, compte) :
            // jamais par une clé d'API, même en écriture — une clé fuitée ne doit pas pouvoir détourner les encaissements.
            $writeOnly = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $request->is('api/company', 'api/social/*', 'api/backups*');
            if ($writeOnly || $request->is('api/integrations*', 'api/users*', 'api/roles*', 'api/auth/*', 'api/admin/*', 'api/system/*',
                'api/payments/gateways*', 'api/messaging/channels*', 'api/messaging/setup*', 'api/messaging/test*', 'api/mailbox/accounts*',
                'api/licenses*', 'api/store*', 'api/me/*', 'api/backups/*/download*')) {
                return response()->json(['message' => 'Action non autorisée avec une clé d\'API.'], 403);
            }
        }

        return $next($request);
    }
}
