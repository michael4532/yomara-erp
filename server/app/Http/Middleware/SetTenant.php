<?php

namespace App\Http\Middleware;

use App\Models\Company;
use Closure;
use Illuminate\Http\Request;

/**
 * Détermine la société courante.
 * Un super admin peut agir dans une société via l'en-tête X-Company-Id.
 */
class SetTenant
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Compte désactivé.'], 403);
        }

        $company = null;
        if ($user->is_super_admin && $request->hasHeader('X-Company-Id')) {
            $company = Company::find($request->header('X-Company-Id'));
        }
        $company ??= $user->company;

        if (! $company) {
            return response()->json(['message' => 'Aucune société associée à ce compte.'], 403);
        }

        // Essai ou abonnement terminé : retour à la formule gratuite plutôt qu'un blocage
        \App\Store\ModuleStore::downgradeIfExpired($company);
        if ($company->isSuspended() && ! $user->is_super_admin) {
            return response()->json([
                'message' => 'L\'accès de votre société est suspendu. Contactez votre fournisseur.',
                'code' => 'company_suspended',
            ], 402);
        }

        app()->instance('tenant', $company);
        // Multi-agences : agences autorisées et agence de travail de l'utilisateur
        app()->instance('branch_filter', null);
        \App\Support\BranchScope::bindFor($request->user(), $company, $request->header('X-Branch-Id'));

        return $next($request);
    }
}
