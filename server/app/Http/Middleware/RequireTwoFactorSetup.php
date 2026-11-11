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
    protected const OPEN = ['api/auth/me', 'api/auth/logout', 'api/auth/password', 'api/auth/2fa', 'api/auth/2fa/*', 'api/me/push-token'];

    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        $token = $u?->currentAccessToken();
        $isApiKey = $token && str_starts_with((string) ($token->name ?? ''), 'api:');
        if ($u && ! $isApiKey && ! $request->is(...self::OPEN) && TwoFactor::setupRequired($u)) {
            return response()->json([
                'code' => 'two_factor_setup_required',
                'message' => 'Activez la double authentification pour continuer (Mon compte → Sécurité).',
            ], 403);
        }

        return $next($request);
    }
}
