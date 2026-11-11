<?php

namespace App\Http\Middleware;

use App\Support\TwoFactor;
use Closure;
use Illuminate\Http\Request;

/**
 * Compte qui doit avoir la double authentification (super administrateur, société qui l'exige) mais ne l'a pas encore :
 * seuls le compte, la déconnexion et la configuration de la double authentification restent accessibles.
 * Les clés d'API (intégrations) ne sont pas concernées.
 */
class RequireTwoFactorSetup
{
    protected const OPEN = ['api/auth/me', 'api/auth/logout', 'api/auth/password', 'api/auth/2fa', 'api/auth/2fa/*', 'api/me/push-token', 'api/admin/system/update'];

    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        $token = $u?->currentAccessToken();
        // Clé d'API d'intégration : droits limités (read / write) ; une session de connexion a tous les droits (« * »).
        // On se fie aux droits, pas au nom : le nom d'appareil est choisi par le client à la connexion.
        $isApiKey = $token instanceof \Laravel\Sanctum\PersonalAccessToken && ! in_array('*', (array) $token->abilities, true);
        if ($u && ! $isApiKey && ! $request->is(...self::OPEN) && TwoFactor::setupRequired($u)) {
            return response()->json([
                'code' => 'two_factor_setup_required',
                'message' => 'Activez la double authentification pour continuer (Mon compte → Sécurité).',
            ], 403);
        }

        return $next($request);
    }
}
